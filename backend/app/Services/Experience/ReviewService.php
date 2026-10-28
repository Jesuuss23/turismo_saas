<?php

namespace App\Services\Experience;

use App\Domain\Bookings\RuleResolver;
use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Collab\Conversation;
use App\Models\Experience\ExternalReview;
use App\Models\Experience\PaxMessage;
use App\Models\Experience\PaxMessageTemplate;
use App\Models\Experience\ReviewSource;
use App\Models\Experience\WidgetConfig;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Collab\InboxService;
use App\Services\Integrations\WebhookService;
use Illuminate\Support\Facades\DB;

/**
 * Reseñas externas (spec 17 `canales/resenas`, §3.7, RV-01..03). Conectores de DEMOSTRACIÓN: «Conectar» guarda la credencial
 * cifrada (solo se muestran los últimos 4 caracteres) y «Sincronizar» importa reseñas **de ejemplo** rotuladas como tales.
 * Fase 1 (priorizados por el cliente): Google, TripAdvisor y Trustpilot; fase 2: Facebook, GetYourGuide y Viator.
 */
class ReviewService
{
    /** Reseñas de ejemplo por plataforma: [autor, días atrás, estrellas, título, texto, idioma, producto]. */
    private const EXAMPLES = [
        ['Lucía M.', 3, 5, 'Machu Picchu inolvidable', 'Todo salió perfecto: el guía Alberto explicó la historia con paciencia y el tren fue puntual. Volvería a reservar con ellos.', 'ES', 'PRD-001'],
        ['James P.', 6, 4, 'Great Sacred Valley day', 'Very well organized tour with a friendly guide. Lunch was a bit rushed but the views at Pisac and Ollantaytambo were amazing.', 'EN', 'PRD-003'],
        ['Carlos R.', 9, 2, 'Recojo tarde', 'El recojo en el hotel llegó 40 minutos tarde y nadie nos avisó. El paisaje bonito, pero la organización debe mejorar.', 'ES', 'PRD-004'],
        ['Anna K.', 12, 5, 'Best trip of our lives', 'The 10-day circuit was flawless: hotels, transfers and the Titicaca extension. Thank you for taking care of every detail!', 'EN', 'PRD-013'],
        ['Rosa T.', 15, 3, 'Bien, pero caro', 'El tour cumple lo prometido; el precio de los adicionales nos pareció alto para lo que incluye.', 'ES', 'PRD-002'],
        ['Mark D.', 20, 5, 'Rainbow Mountain at sunrise', 'Hard hike but worth it. The team carried oxygen and checked on everyone. Breakfast stop was great.', 'EN', 'PRD-004'],
        ['Valeria S.', 24, 4, 'Muy recomendable', 'Excelente atención por WhatsApp antes del viaje y guía muy preparado en Cusco.', 'ES', 'PRD-012'],
        ['Thomas B.', 30, 1, 'Cancelled without notice', 'Our Humantay tour was cancelled the night before and the refund took two weeks. Disappointing.', 'EN', 'PRD-011'],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RuleResolver $rules,
        private readonly InboxService $inbox,
    ) {}

    // --- conectores ---

    public function sources(): array
    {
        return ReviewSource::orderBy('provider')->get()->sortBy(fn (ReviewSource $s) => array_search($s->provider, array_keys(ExperienceCatalog::REVIEW_PROVIDERS), true))
            ->map(fn (ReviewSource $s) => $this->presentSource($s))->values()->all();
    }

    /** RV-03: nunca el secreto; solo los últimos 4 caracteres. */
    public function presentSource(ReviewSource $s): array
    {
        [$name, $phase, , , $scopes] = ExperienceCatalog::REVIEW_PROVIDERS[$s->provider];

        return [
            'id' => $s->id, 'provider' => $s->provider, 'name' => $name, 'phase' => $phase, 'status' => $s->status, 'account_ref' => $s->account_ref,
            'secret_hint' => $s->secret_last4 ? '••••'.$s->secret_last4 : null, 'scopes' => $s->scopes ?: $scopes, 'can_reply' => $s->can_reply, 'supports_invites' => $s->supports_invites,
            'rating' => $s->rating !== null ? (float) $s->rating : null, 'review_count' => $s->review_count, 'last_sync_at' => $s->last_sync_at?->toIso8601String(),
            'last_error' => $s->last_error, 'connected_at' => $s->connected_at?->toIso8601String(), 'contract_ack_at' => $s->contract_ack_at?->toIso8601String(),
        ];
    }

    public function connect(string $provider, array $data, User $actor): array
    {
        $s = $this->source($provider);
        $secret = trim((string) ($data['secret'] ?? $data['api_key'] ?? ''));
        $account = trim((string) ($data['account_ref'] ?? ''));
        if ($account === '' || mb_strlen($account) > 120) {
            throw ApiException::invalid('account_ref', 'Indique la cuenta o ubicación (hasta 120 caracteres).');
        }
        if (mb_strlen($secret) < 8 || mb_strlen($secret) > 500) {
            throw ApiException::invalid('secret', 'La credencial debe tener al menos 8 caracteres.');
        }
        if (! filter_var($data['contract_ack'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ApiException::unprocessable('RV-01', 'RV-01: declare que la agencia tiene contrato o aprobación de la plataforma para usar su API oficial.');
        }
        DB::transaction(function () use ($s, $secret, $account, $actor) {
            $s->forceFill(['status' => 'conectado', 'account_ref' => $account, 'secret_encrypted' => $secret, 'secret_last4' => mb_substr($secret, -4),
                'secret_ref' => "vault://reviews/{$s->provider}", 'connected_at' => now(), 'connected_by' => $actor->id, 'contract_ack_at' => now(), 'contract_ack_by' => $actor->id,
                'last_error' => null])->save();
            $this->audit->log('Integración de reseñas', $s->provider, 'conectar', null, "{$account} · alcance ".implode(', ', (array) $s->scopes).' · ••••'.$s->secret_last4, actor: $actor);
        });
        $this->sync($s->fresh(), $actor);

        return $this->presentSource($s->fresh());
    }

    /** Revoca el token y borra el secreto; las reseñas importadas se conservan. */
    public function disconnect(string $provider, User $actor): array
    {
        $s = $this->source($provider);
        $s->forceFill(['status' => 'no_conectado', 'secret_encrypted' => null, 'secret_last4' => null, 'secret_ref' => null, 'account_ref' => null, 'last_error' => null])->save();
        $this->audit->log('Integración de reseñas', $s->provider, 'desconectar', 'conectado', 'no_conectado', actor: $actor);

        return $this->presentSource($s);
    }

    /** SyncExternalReviews (demo): importa hasta 3 reseñas de ejemplo nuevas (upsert por external_id); credencial «invalid…» simula 401 → error. */
    public function sync(ReviewSource|string $source, ?User $actor = null): array
    {
        $s = $source instanceof ReviewSource ? $source : $this->source($source);
        if (! in_array($s->status, ['conectado', 'error'], true)) {
            throw ApiException::unprocessable('RV-05', 'Conecte la plataforma antes de sincronizar.');
        }
        if (str_starts_with((string) $s->secret_encrypted, 'invalid')) {
            $s->forceFill(['status' => 'error', 'last_error' => '401 Unauthorized: credencial rechazada por la plataforma', 'last_sync_at' => now()])->save();
            $this->audit->log('Integración de reseñas', $s->provider, 'sincronizar', null, 'error 401', actor: $actor, system: $actor === null);

            return ['imported' => 0, 'status' => 'error', 'source' => $this->presentSource($s)];
        }
        $products = Product::pluck('id', 'ref');
        $offset = array_search($s->provider, array_keys(ExperienceCatalog::REVIEW_PROVIDERS), true);
        $imported = 0;
        $have = ExternalReview::where('source_id', $s->id)->pluck('external_id')->all();
        foreach (self::EXAMPLES as $i => [$author, $daysAgo, $rating, $title, $body, $lang, $ref]) {
            if (($i + $offset) % 2 === 1 && $s->provider !== 'google') {
                continue;
            }
            $external = "{$s->provider}-ex-".($i + 1);
            if (in_array($external, $have, true) || $imported >= 3) {
                continue;
            }
            $review = ExternalReview::create([
                'source_id' => $s->id, 'external_id' => $external, 'author_display' => $author, 'review_date' => now()->subDays($daysAgo + $offset)->toDateString(), 'rating' => $rating,
                'title' => $title, 'body' => $body, 'lang' => $lang, 'product_id' => $products[$ref] ?? null, 'url' => "https://example.com/{$s->provider}/review/{$external}",
                'response_status' => $s->can_reply ? 'sin_responder' : 'no_admite', 'is_example' => true, 'raw' => ['demo' => true, 'provider' => $s->provider],
            ]);
            app(WebhookService::class)->dispatch('review.received', $external, ['provider' => $s->provider, 'rating' => $rating, 'author' => $author, 'is_example' => true,
                'review_id' => $review->id]);
            $imported++;
        }
        $stats = ExternalReview::where('source_id', $s->id)->selectRaw('count(*) AS n, avg(rating) AS avg')->first();
        $s->forceFill(['status' => 'conectado', 'last_error' => null, 'last_sync_at' => now(), 'review_count' => (int) $stats->n, 'rating' => $stats->n ? round((float) $stats->avg, 2) : null])->save();
        $this->audit->log('Integración de reseñas', $s->provider, 'sincronizar', null, "{$imported} reseña(s) nuevas de ejemplo", actor: $actor, system: $actor === null);

        return ['imported' => $imported, 'status' => 'conectado', 'source' => $this->presentSource($s)];
    }

    // --- bandeja ---

    public function inbox(array $filters): array
    {
        $q = ExternalReview::with('source')->orderByDesc('review_date')->orderByDesc('created_at');
        if (! empty($filters['provider'])) {
            $q->whereHas('source', fn ($s) => $s->where('provider', $filters['provider']));
        }
        if (! empty($filters['status'])) {
            $q->where('response_status', $filters['status']);
        }
        if (! empty($filters['max_rating'])) {
            $q->where('rating', '<=', (int) $filters['max_rating']);
        }
        $rows = $q->limit(200)->get();
        $products = Product::whereIn('id', $rows->pluck('product_id')->filter())->get(['id', 'ref', 'name'])->keyBy('id');

        return [
            'items' => $rows->map(fn (ExternalReview $r) => $this->present($r, $products[$r->product_id] ?? null))->all(),
            'kpis' => [
                'count' => ExternalReview::count(), 'avg' => round((float) ExternalReview::avg('rating'), 2),
                'unanswered' => ExternalReview::whereIn('response_status', ['sin_responder', 'no_admite'])->whereNull('reply')->count(),
                'low' => ExternalReview::where('rating', '<=', 3)->whereNull('conversation_id')->count(), 'is_example' => ExternalReview::where('is_example', true)->exists(),
            ],
        ];
    }

    public function present(ExternalReview $r, ?Product $product = null): array
    {
        return [
            'id' => $r->id, 'provider' => $r->source?->provider, 'source_name' => ExperienceCatalog::REVIEW_PROVIDERS[$r->source?->provider][0] ?? null, 'external_id' => $r->external_id,
            'author' => $r->author_display, 'date' => $r->review_date?->toDateString(), 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body, 'lang' => $r->lang,
            'product' => $product ? ['id' => $product->id, 'ref' => $product->ref, 'name' => $product->name] : null, 'url' => $r->url, 'response_status' => $r->response_status,
            'reply' => $r->reply, 'replied_at' => $r->replied_at?->toIso8601String(), 'can_reply' => (bool) $r->source?->can_reply, 'conversation_id' => $r->conversation_id,
            'conversation_code' => $r->conversation_id ? Conversation::whereKey($r->conversation_id)->value('code') : null, 'is_example' => $r->is_example,
            'suggested_reply' => $this->suggested($r),
        ];
    }

    /** RV-02: ≥ 20 caracteres; con API de respuesta → «pendiente de publicar» (PublishReviewReply); solo lectura → guardar y copiar. */
    public function reply(ExternalReview $r, string $text, User $actor): array
    {
        $text = trim($text);
        if (mb_strlen($text) < 20 || mb_strlen($text) > 4000) {
            throw ApiException::unprocessable('RV-02', 'RV-02: la respuesta debe tener al menos 20 caracteres.');
        }
        $r->loadMissing('source');
        $status = $r->source->can_reply ? 'pendiente_publicar' : 'no_admite';
        $r->forceFill(['reply' => $text, 'replied_at' => now(), 'replied_by' => $actor->id, 'response_status' => $status])->save();
        $this->audit->log('Reseña externa', $r->external_id, 'responder', null, ($status === 'pendiente_publicar' ? 'Pendiente de publicar' : 'Guardada para publicar manualmente').' · '.mb_substr($text, 0, 120), actor: $actor);

        return $this->present($r) + ['copy' => $status === 'no_admite' ? $text : null];
    }

    public function markPublished(ExternalReview $r, User $actor): array
    {
        if (! $r->reply) {
            throw ApiException::unprocessable('RV-02', 'Guarde una respuesta antes de marcarla como publicada.');
        }
        $before = $r->response_status;
        $r->forceFill(['response_status' => 'publicada'])->save();
        $this->audit->log('Reseña externa', $r->external_id, 'marcar_publicada', $before, 'publicada', actor: $actor);

        return $this->present($r);
    }

    /** «Derivar a postventa»: conversación en el inbox para reseñas de 3★ o menos. */
    public function escalate(ExternalReview $r, User $actor): array
    {
        if ($r->rating > 3) {
            throw ApiException::unprocessable('RV-04', 'Solo se derivan a postventa las reseñas de 3 estrellas o menos.');
        }
        if ($r->conversation_id) {
            throw ApiException::unprocessable('RV-04', 'La reseña ya fue derivada a postventa.');
        }
        $r->loadMissing('source');
        $name = ExperienceCatalog::REVIEW_PROVIDERS[$r->source->provider][0];
        $conversation = DB::transaction(function () use ($r, $actor, $name) {
            $c = $this->inbox->open(['channel' => 'form', 'contact_name' => $r->author_display, 'subject' => mb_substr("Reseña {$r->rating}★ en {$name}: {$r->title}", 0, 200),
                'priority' => $r->rating <= 2 ? 'alta' : 'media', 'tags' => ['postventa', 'reseña externa', $r->source->provider]]);
            $this->inbox->inbound($c, "«{$r->body}» — {$r->author_display}, {$r->review_date->format('d/m/Y')} ({$name}".($r->is_example ? ', reseña de ejemplo' : '').')');
            $this->inbox->system($c, "Derivada desde Canales › Reseñas por {$actor->name}.", $actor);
            $r->forceFill(['conversation_id' => $c->id])->save();
            $this->audit->log('Reseña externa', $r->external_id, 'derivar_postventa', null, $c->code, actor: $actor);

            return $c;
        });

        return $this->present($r->fresh()) + ['conversation' => ['id' => $conversation->id, 'code' => $conversation->code]];
    }

    /** PublishReviewReply (demo): en las APIs que permiten responder, lo pendiente pasa a publicada. */
    public function publishPending(): int
    {
        $n = 0;
        foreach (ExternalReview::with('source')->where('response_status', 'pendiente_publicar')->where('replied_at', '<=', now()->subMinutes(30))->get() as $r) {
            if ($r->source?->status === 'conectado' && $r->source->can_reply) {
                $r->forceFill(['response_status' => 'publicada'])->save();
                $this->audit->log('Reseña externa', $r->external_id, 'marcar_publicada', 'pendiente_publicar', 'publicada (API)', system: true);
                $n++;
            }
        }

        return $n;
    }

    // --- invitaciones postviaje (R-REVIEW-INVITE) ---

    public function invitations(): array
    {
        $template = $this->inviteTemplates();
        $invited = PaxMessage::whereIn('template_id', $template->pluck('id'))->where('status', '<>', 'cancelado')->get()->groupBy('reservation_id');
        $today = now()->toDateString();

        return [
            'rule_on' => $this->rules->on('R-REVIEW-INVITE'), 'days_after' => (int) $this->rules->param('R-REVIEW-INVITE', 'daysAfter', null, 2),
            'items' => Reservation::with('client:id,name,email,country')->whereNotIn('status', [Reservation::CANCELLED, Reservation::DRAFT])->where('travel_to', '<', $today)
                ->orderByDesc('travel_to')->limit(50)->get()->map(function (Reservation $r) use ($invited) {
                    $msgs = $invited[$r->id] ?? collect();

                    return ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'travel_to' => $r->travel_to->toDateString(), 'client' => $r->client?->name,
                        'email' => (bool) $r->client?->email, 'invited' => $msgs->isNotEmpty(), 'invite_status' => $msgs->sortByDesc('created_at')->first()?->status,
                        'invited_at' => $msgs->sortByDesc('created_at')->first()?->created_at?->toIso8601String()];
                })->all(),
        ];
    }

    /** Invita a reseñar (sin gating ni incentivos: a todos por igual). Requiere R-REVIEW-INVITE. */
    public function invite(Reservation $r, User $actor): array
    {
        if (! $this->rules->on('R-REVIEW-INVITE')) {
            throw ApiException::unprocessable('RULE_OFF', 'La regla «Invitación a reseñar» (R-REVIEW-INVITE) está desactivada o fuera de su plan.');
        }
        if (in_array($r->status, [Reservation::CANCELLED, Reservation::DRAFT], true) || $r->travel_to->toDateString() >= now()->toDateString()) {
            throw ApiException::unprocessable('RV-01', 'Solo se invita a reseñar a reservas con el viaje terminado.');
        }
        $r->loadMissing('client');
        $lang = PaxMessageService::langFor($r);
        $templates = $this->inviteTemplates();
        $template = $templates->firstWhere('lang', $lang) ?? $templates->firstWhere('lang', 'ES') ?? $templates->first()
            ?? throw ApiException::unprocessable('RV-01', 'No hay una plantilla activa de invitación a reseñar (evento fin de viaje con R-REVIEW-INVITE).');
        $result = app(PaxMessageService::class)->manual($r, ['channel' => 'email', 'template_id' => $template->id], $actor);
        $this->audit->log('Reseña externa', $r->code, 'invitar', null, "{$template->code} · {$result['code']}", actor: $actor);

        return $result;
    }

    private function inviteTemplates()
    {
        return PaxMessageTemplate::where('active', true)->where('event', 'fin_viaje')->whereRaw("conditions->>'requiresRule' = 'R-REVIEW-INVITE'")->orderBy('code')->get();
    }

    // --- widget de reseñas para el sitio ---

    public function widgetConfig(): array
    {
        $cfg = WidgetConfig::first();

        return (array) ($cfg?->reviews_widget ?? []) + ['sources' => ['google', 'tripadvisor', 'trustpilot'], 'min_rating' => 4, 'count' => 6, 'layout' => 'carrusel'];
    }

    public function saveWidgetConfig(array $data, User $actor): array
    {
        $sources = array_values(array_intersect(array_keys(ExperienceCatalog::REVIEW_PROVIDERS), (array) ($data['sources'] ?? [])));
        if ($sources === []) {
            throw ApiException::invalid('sources', 'Elija al menos una plataforma.');
        }
        $min = (int) ($data['min_rating'] ?? 4);
        $count = (int) ($data['count'] ?? 6);
        $layout = (string) ($data['layout'] ?? 'carrusel');
        if ($min < 1 || $min > 5 || $count < 1 || $count > 20 || ! in_array($layout, ['carrusel', 'lista', 'cuadricula'], true)) {
            throw ApiException::invalid('count', 'Mínimo de estrellas 1–5, cantidad 1–20 y diseño carrusel, lista o cuadrícula.');
        }
        $cfg = WidgetConfig::first() ?? throw ApiException::notFound();
        $before = json_encode($cfg->reviews_widget);
        $cfg->reviews_widget = ['sources' => $sources, 'min_rating' => $min, 'count' => $count, 'layout' => $layout];
        $cfg->save();
        $this->audit->log('Widget de reseñas', 'config', 'editar', $before, json_encode($cfg->reviews_widget), actor: $actor);

        return $this->widgetConfig();
    }

    /** GET /public/reviews: reseñas con atribución de la plataforma (y rótulo «de ejemplo»). */
    public function publicReviews(array $q): array
    {
        $cfg = $this->widgetConfig();
        $sources = array_values(array_intersect(array_keys(ExperienceCatalog::REVIEW_PROVIDERS), $q['sources'] ?? $cfg['sources']));
        $min = max(1, min(5, (int) ($q['min_rating'] ?? $cfg['min_rating'])));
        $count = max(1, min(20, (int) ($q['count'] ?? $cfg['count'])));
        $rows = ExternalReview::with('source')->whereHas('source', fn ($s) => $s->whereIn('provider', $sources)->where('status', 'conectado'))
            ->where('rating', '>=', $min)->orderByDesc('review_date')->limit($count)->get();

        return [
            'items' => $rows->map(fn (ExternalReview $r) => ['author' => $r->author_display, 'date' => $r->review_date->toDateString(), 'rating' => $r->rating, 'title' => $r->title,
                'body' => $r->body, 'lang' => $r->lang, 'source' => $r->source->provider, 'source_name' => ExperienceCatalog::REVIEW_PROVIDERS[$r->source->provider][0],
                'url' => $r->url, 'is_example' => $r->is_example])->all(),
            'summary' => ReviewSource::whereIn('provider', $sources)->where('status', 'conectado')->get()
                ->map(fn (ReviewSource $s) => ['source' => $s->provider, 'rating' => $s->rating !== null ? (float) $s->rating : null, 'count' => $s->review_count])->all(),
            'layout' => $cfg['layout'], 'is_example' => $rows->contains('is_example', true),
            'attribution' => 'Reseñas publicadas en plataformas externas; se muestran todas las que cumplen el filtro, sin edición.',
        ];
    }

    private function suggested(ExternalReview $r): string
    {
        $en = $r->lang === 'EN';
        $name = explode(' ', $r->author_display)[0];

        return match (true) {
            $r->rating >= 4 => $en ? "Thank you so much, {$name}! We are delighted you enjoyed the experience and hope to welcome you back to Peru soon."
                : "¡Muchas gracias, {$name}! Nos alegra mucho que haya disfrutado la experiencia y esperamos recibirle de nuevo pronto.",
            default => $en ? "Dear {$name}, thank you for your honest feedback. We are sorry about what happened; our after-sales team will contact you to make it right."
                : "Estimado/a {$name}, gracias por su comentario. Lamentamos lo ocurrido; nuestro equipo de postventa le contactará para resolverlo.",
        };
    }

    private function source(string $provider): ReviewSource
    {
        return ReviewSource::where('provider', strtolower($provider))->first() ?? throw ApiException::notFound();
    }
}
