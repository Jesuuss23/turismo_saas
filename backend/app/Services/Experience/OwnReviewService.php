<?php

namespace App\Services\Experience;

use App\Domain\Bookings\RuleResolver;
use App\Exceptions\ApiException;
use App\Models\Admin\Setting;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Crm\Client;
use App\Models\Experience\OwnReview;
use App\Models\Experience\OwnReviewLog;
use App\Models\Experience\ReviewRequest;
use App\Models\Iam\User;
use App\Models\Sys\BookingRule;
use App\Services\AuditLogger;
use App\Services\Catalog\Booking\BookingConfigService;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reseñas propias verificadas (spec 18, R-OWN-REVIEWS): solo califican los pasajeros de un viaje realizado (RVW-02), una reseña por
 * pasajero, reserva y producto (RVW-03); moderación con motivo y sin gating (RVW-07), datos personales retenidos o enmascarados
 * (RVW-06) y salida pública solo de lo publicado (RVW-10). Coexiste con las reseñas externas de la spec 17.
 */
class OwnReviewService
{
    public const RULE = 'R-OWN-REVIEWS';

    public const LANGS = ['es' => 'Español', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'pt' => 'Português'];

    public const MASK = '[dato oculto]';

    /** @var array<string, array> */
    private array $summaryMemo = [];

    private const PHOTO_MAX = 3;

    private const PHOTO_BYTES = 2 * 1024 * 1024;

    private const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RuleResolver $rules,
        private readonly SequenceService $sequences,
        private readonly CrmClock $clock,
        private readonly Notify $notify,
        private readonly TenantContext $tenant,
    ) {}

    // --- regla (RVW-01) y ajustes (§3.4) ---

    /** on | off_tenant | off_plan | off_product. */
    public function state(Product|string|null $product = null): string
    {
        return $this->rules->state(self::RULE, $product);
    }

    public function effective(Product|string|null $product = null): bool
    {
        return $this->rules->on(self::RULE, $product);
    }

    /** RVW-01: sin la regla efectiva no hay formulario, invitaciones ni salida pública (los datos se conservan). */
    public function ensureEffective(Product|string|null $product = null): void
    {
        if (! $this->rules->planAllows(self::RULE)) {
            throw ApiException::forbidden('RULE_NOT_IN_PLAN', 'Las reseñas propias están disponibles desde el plan '.ucfirst((string) $this->rules->minPlan(self::RULE)).'.');
        }
        if (! $this->effective($product)) {
            throw ApiException::forbidden('RULE_DISABLED', $this->rules->tenantOn(self::RULE)
                ? 'Las reseñas propias están desactivadas para este producto.' : 'Las reseñas propias (R-OWN-REVIEWS) están desactivadas en el centro de reglas.');
        }
    }

    /** @return array{daysAfter: int, minStarsAutoPublish: int, moderation: bool} */
    public function params(Product|string|null $product = null): array
    {
        $p = $this->rules->params(self::RULE, $product);

        return ['daysAfter' => (int) ($p['daysAfter'] ?? 1), 'minStarsAutoPublish' => (int) ($p['minStarsAutoPublish'] ?? 4), 'moderation' => (bool) ($p['moderation'] ?? true)];
    }

    public function settings(): array
    {
        $values = (array) (Setting::where('section', 'own_reviews')->value('values') ?? []);
        $defaults = OwnReviewProvisioner::defaults();

        return array_replace($defaults, $values, ['invite' => array_replace($defaults['invite'], (array) ($values['invite'] ?? []))]);
    }

    public function config(User $user): array
    {
        $rule = $this->rules->rule(self::RULE);

        return [
            'state' => $this->state(), 'min_plan' => $this->rules->minPlan(self::RULE), 'plan' => $this->rules->plan(), 'params' => $this->params(),
            'rule_lock_version' => $rule ? (int) DB::table('sys_booking_rules')->where('rule_key', self::RULE)->value('lock_version') : null,
            'settings' => $this->settings(), 'subratings' => OwnReviewProvisioner::SUBRATINGS, 'langs' => self::LANGS,
            'can_moderate' => $user->hasPermissionTo('channels.ecommerce.manage'), 'can_edit_rule' => $user->hasPermissionTo('admin.settings.manage'),
            'own_portfolio' => $this->portfolioOnly($user),
        ];
    }

    /**
     * PATCH /settings/own-reviews: ajustes del módulo (channels.ecommerce.manage) y, con admin.settings.manage, los parámetros de la regla
     * (daysAfter, minStarsAutoPublish, moderation), que viven en sys_booking_rules.
     */
    public function saveSettings(array $data, User $actor): array
    {
        $current = $this->settings();
        $patch = [];
        foreach (['auto_publish', 'show_on_site', 'show_on_widget'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $patch[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('personal_data', $data)) {
            if (! in_array($data['personal_data'], ['retener', 'enmascarar'], true)) {
                throw ApiException::invalid('personal_data', 'Elija «retener» o «enmascarar».');
            }
            $patch['personal_data'] = $data['personal_data'];
        }
        if (array_key_exists('banned_words', $data)) {
            $words = is_array($data['banned_words']) ? $data['banned_words'] : preg_split('/[,\n]/', (string) $data['banned_words']);
            $words = array_values(array_unique(array_filter(array_map(fn ($w) => mb_strtolower(trim((string) $w)), $words), fn ($w) => $w !== '')));
            if (count($words) > 200 || collect($words)->contains(fn ($w) => mb_strlen($w) > 40)) {
                throw ApiException::invalid('banned_words', 'Hasta 200 palabras de 40 caracteres como máximo.');
            }
            $patch['banned_words'] = $words;
        }
        if (array_key_exists('active_subratings', $data)) {
            $subs = array_values(array_intersect(array_keys(OwnReviewProvisioner::SUBRATINGS), (array) ($data['active_subratings'] ?? [])));
            $patch['active_subratings'] = $subs;
        }
        if (isset($data['invite']) && is_array($data['invite'])) {
            $invite = $current['invite'];
            $in = $data['invite'];
            if (array_key_exists('channel', $in)) {
                $invite['channel'] = in_array($in['channel'], ['email', 'whatsapp'], true) ? $in['channel'] : throw ApiException::invalid('invite.channel', 'Canal: email o WhatsApp.');
            }
            foreach (['reminder_days' => [0, 30], 'expire_days' => [1, 90]] as $k => [$min, $max]) {
                if (array_key_exists($k, $in)) {
                    if (! is_numeric($in[$k]) || (int) $in[$k] != $in[$k] || $in[$k] < $min || $in[$k] > $max) {
                        throw ApiException::invalid("invite.{$k}", "Debe ser un número entero entre {$min} y {$max}.");
                    }
                    $invite[$k] = (int) $in[$k];
                }
            }
            foreach (['subject' => [5, 160], 'body' => [20, 2000]] as $k => [$min, $max]) {
                if (array_key_exists($k, $in)) {
                    $text = trim((string) $in[$k]);
                    if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
                        throw ApiException::invalid("invite.{$k}", "Entre {$min} y {$max} caracteres.");
                    }
                    if ($k === 'body' && ! str_contains($text, '{enlace}')) {
                        throw ApiException::invalid('invite.body', 'El texto debe incluir {enlace}.');
                    }
                    // RVW-09: sin incentivos.
                    if (preg_match('/\b(descuento|cup[oó]n|premio|sorteo|regalo|gratis)\b/iu', $text)) {
                        throw ApiException::unprocessable('RVW-09', 'RVW-09: la invitación no puede ofrecer incentivos (descuentos, premios, sorteos o regalos).');
                    }
                    $invite[$k] = $text;
                }
            }
            $patch['invite'] = $invite;
        }

        $ruleParams = array_intersect_key((array) ($data['params'] ?? []), array_flip(['daysAfter', 'minStarsAutoPublish', 'moderation']));
        if ($ruleParams !== [] && ! $actor->hasPermissionTo('admin.settings.manage')) {
            throw ApiException::forbidden('ADMIN_ONLY', 'Los parámetros de la regla R-OWN-REVIEWS solo los cambia el administrador.', ['admin.settings.manage']);
        }

        DB::transaction(function () use ($patch, $current, $ruleParams, $actor) {
            if ($patch !== []) {
                $values = array_replace($current, $patch, ['is_demo' => false]);
                $row = Setting::where('section', 'own_reviews')->first();
                $row ? $row->forceFill(['values' => $values])->save() : Setting::create(['section' => 'own_reviews', 'values' => $values]);
                $before = array_intersect_key($current, $patch);
                $this->audit->log('Reseñas propias', 'ajustes', 'ajustes/actualizar', json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($patch, JSON_UNESCAPED_UNICODE), actor: $actor,
                    diff: ['before' => $before, 'after' => $patch]);
            }
            if ($ruleParams !== []) {
                $rule = BookingRule::where('rule_key', self::RULE)->firstOrFail();
                $valid = BookingConfigService::validParams(['key' => self::RULE, 'schema' => $rule->params_schema], $ruleParams);
                $before = (array) $rule->params;
                $rule->params = (object) array_replace($before, $valid);
                if ($rule->isDirty('params')) {
                    $rule->save();
                    $this->rules->bump();
                    $this->audit->log('Regla de reserva R-OWN-REVIEWS', self::RULE, 'parametros', json_encode((object) $before), json_encode($rule->params), actor: $actor,
                        diff: ['before' => (object) $before, 'after' => $rule->params]);
                }
            }
        });
        $this->invalidate();

        return $this->config($actor);
    }

    // --- bandeja y listados (backoffice) ---

    /** RVW-11: Ventas (sin channels.ecommerce.manage) solo ve sus reservas o los clientes de su cartera. */
    public function portfolioOnly(User $user): bool
    {
        return ! $user->hasPermissionTo('channels.ecommerce.manage');
    }

    public function scoped(User $user): Builder
    {
        $q = OwnReview::query();
        if ($this->portfolioOnly($user)) {
            $q->where(fn ($w) => $w->whereIn('client_id', Client::where('owner_id', $user->id)->select('id'))
                ->orWhereIn('reservation_id', Reservation::where('owner_id', $user->id)->select('id')));
        }

        return $q;
    }

    public function list(array $f, User $user): array
    {
        $q = $this->scoped($user)->with(['product:id,ref,name', 'client:id,code,name,owner_id']);
        if (($s = $f['status'] ?? null) && isset(OwnReview::STATUSES[$s])) {
            $q->where('status', $s);
        }
        if (($f['tab'] ?? null) === 'bandeja') {
            $q->where('status', 'pendiente');
        }
        if (! empty($f['stars']) && (int) $f['stars'] >= 1 && (int) $f['stars'] <= 5) {
            $q->where('stars', (int) $f['stars']);
        }
        if ($ref = $f['product'] ?? null) {
            $q->whereHas('product', fn ($p) => $p->where('ref', $ref));
        }
        if (! empty($f['flagged'])) {
            $q->whereRaw('jsonb_array_length(flags) > 0');
        }
        if ($term = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.mb_strtolower($term).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(body) like ?', [$like])->orWhereRaw('lower(author_name) like ?', [$like])
                ->orWhereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(reservation_code) like ?', [$like]));
        }
        $rows = $q->orderByRaw("CASE status WHEN 'pendiente' THEN 0 ELSE 1 END")->orderByDesc('created_at')->limit(500)->get();

        return $rows->map(fn (OwnReview $r) => $this->present($r))->all();
    }

    public function stats(User $user): array
    {
        $base = $this->scoped($user);
        $all = (clone $base)->get(['id', 'stars', 'status', 'product_id', 'subratings', 'flags']);
        $published = $all->where('status', 'publicada');
        $dist = [];
        foreach ([5, 4, 3, 2, 1] as $n) {
            $dist[$n] = $published->where('stars', $n)->count();
        }
        $products = Product::whereIn('id', $published->pluck('product_id')->unique())->get(['id', 'ref', 'name'])->keyBy('id');
        $subs = [];
        foreach (array_keys(OwnReviewProvisioner::SUBRATINGS) as $k) {
            $vals = $published->map(fn ($r) => $r->subratings[$k] ?? null)->filter();
            $subs[$k] = $vals->isEmpty() ? null : round($vals->avg(), 2);
        }

        return [
            'received' => $all->count(), 'published' => $published->count(),
            'average' => $published->isEmpty() ? null : round($published->avg('stars'), 2),
            'recommend_pct' => $published->isEmpty() ? null : round($published->where('stars', '>=', 4)->count() * 100 / $published->count()),
            'pending' => $all->where('status', 'pendiente')->count(),
            'pending_flagged' => $all->where('status', 'pendiente')->filter(fn ($r) => ! empty($r->flags))->count(),
            'distribution' => $dist,
            'by_product' => $published->groupBy('product_id')->map(fn ($g, $id) => ['ref' => $products[$id]->ref ?? '', 'name' => $products[$id]->name ?? '',
                'average' => round($g->avg('stars'), 2), 'count' => $g->count()])->sortByDesc('count')->values()->all(),
            'subratings' => $subs,
            'latest' => (clone $base)->where('status', 'publicada')->with('product:id,ref,name')->orderByDesc('published_at')->limit(5)->get()
                ->map(fn (OwnReview $r) => $this->present($r))->all(),
        ];
    }

    public function find(string $code, User $user): OwnReview
    {
        $review = OwnReview::where('code', $code)->first() ?? throw ApiException::notFound();
        if (! $this->scoped($user)->whereKey($review->id)->exists()) {
            throw ApiException::forbidden('OWN_PORTFOLIO', 'Esta reseña es de un cliente de otra cartera.');
        }

        return $review;
    }

    public function show(OwnReview $r): array
    {
        $users = User::whereIn('id', $r->log->pluck('user_id')->filter()->push($r->reply_by)->filter()->unique())->pluck('name', 'id');

        return $this->present($r, true) + ['log' => $r->log->map(fn (OwnReviewLog $l) => ['action' => $l->action, 'note' => $l->note,
            'user' => $l->user_id ? ($users[$l->user_id] ?? '—') : 'Sistema', 'at' => $l->created_at->toIso8601String()])->all()];
    }

    public function present(OwnReview $r, bool $full = false): array
    {
        $r->loadMissing(['product:id,ref,name', 'client:id,code,name,owner_id']);
        $owner = $r->client?->owner_id ? User::whereKey($r->client->owner_id)->value('name') : null;

        return [
            'id' => $r->id, 'code' => $r->code, 'status' => $r->status, 'status_label' => OwnReview::STATUSES[$r->status], 'pax_label' => OwnReview::PAX_LABELS[$r->status],
            'stars' => $r->stars, 'subratings' => (object) ($r->subratings ?? []), 'title' => $r->title, 'body' => $r->body, 'lang' => $r->lang,
            'author_display' => $r->author_display, 'author_name' => $r->author_name, 'country' => $r->country, 'travel_date' => $r->travel_date->toDateString(),
            'product' => $r->product ? ['ref' => $r->product->ref, 'name' => $r->product->name] : null,
            'reservation' => $r->reservation_code, 'historic' => $r->reservation_id === null,
            'client' => $r->client ? ['code' => $r->client->code, 'name' => $r->client->name, 'owner' => $owner] : null,
            'photos' => collect($r->photos ?? [])->map(fn ($p) => ['name' => $p['name'] ?? 'foto'])->all(),
            'verified' => $r->verified, 'consent' => $r->consent_publish, 'flags' => $r->flags ?? [], 'moderation_reason' => $r->moderation_reason,
            'reply' => $r->reply_text, 'replied_at' => $r->replied_at?->toIso8601String(), 'source' => $r->source, 'is_demo' => $r->is_demo,
            'published_at' => $r->published_at?->toIso8601String(), 'created_at' => $r->created_at->toIso8601String(), 'lock_version' => $r->lock_version,
        ] + ($full ? ['helpful_count' => $r->helpful_count, 'request' => $r->request_id ? ReviewRequest::whereKey($r->request_id)->value('code') : null] : []);
    }

    // --- moderación (§4) ---

    /** pendiente/oculta/rechazada → publicada: exige consentimiento (RVW-05); con alertas, confirmación explícita (`confirm_flags`). */
    public function publish(OwnReview $r, bool $confirmFlags, User $actor): array
    {
        if ($r->status === 'publicada') {
            throw ApiException::unprocessable('RVW-STATE', 'La reseña ya está publicada.');
        }
        if (! $r->consent_publish) {
            throw ApiException::unprocessable('RVW-05', 'RVW-05: el pasajero no autorizó publicar esta reseña; solo queda para la agencia.');
        }
        if (! empty($r->flags) && ! $confirmFlags) {
            throw ApiException::unprocessable('RVW-06', 'RVW-06: la reseña tiene alertas ('.implode('; ', $r->flags).'). Confirme que revisó el texto para publicarla.', ['flags' => $r->flags]);
        }

        return $this->transition($r, 'publicada', 'publicar', $confirmFlags && ! empty($r->flags) ? 'Publicada pese a alertas: '.implode('; ', $r->flags) : null, $actor);
    }

    /** RVW-07: rechazar y ocultar exigen motivo; las estrellas nunca son motivo (sin gating). */
    public function reject(OwnReview $r, string $reason, User $actor): array
    {
        if (! in_array($r->status, ['pendiente', 'publicada', 'oculta'], true)) {
            throw ApiException::unprocessable('RVW-STATE', 'Solo se rechaza una reseña pendiente, publicada u oculta.');
        }

        return $this->transition($r, 'rechazada', 'rechazar', $this->reason($reason), $actor);
    }

    public function hide(OwnReview $r, string $reason, User $actor): array
    {
        if (! in_array($r->status, ['pendiente', 'publicada', 'rechazada'], true)) {
            throw ApiException::unprocessable('RVW-STATE', 'Solo se oculta una reseña pendiente, publicada o rechazada.');
        }

        return $this->transition($r, 'oculta', 'ocultar', $this->reason($reason), $actor);
    }

    /** RVW-08: la respuesta pública no lleva datos personales; se avisa al pasajero (correo + mensaje en su portal). No cambia el estado. */
    public function reply(OwnReview $r, string $text, User $actor): array
    {
        $text = trim($text);
        if (mb_strlen($text) < 5 || mb_strlen($text) > 1500) {
            throw ApiException::invalid('text', 'La respuesta debe tener entre 5 y 1500 caracteres.');
        }
        if ($found = $this->personalData($text)) {
            throw ApiException::unprocessable('RVW-08', 'RVW-08: la respuesta es pública y no puede incluir datos personales ('.implode(', ', $found).').');
        }
        $action = $r->reply_text ? 'editar_respuesta' : 'responder';
        DB::transaction(function () use ($r, $text, $actor, $action) {
            $before = $r->reply_text;
            $r->forceFill(['reply_text' => $text, 'reply_by' => $actor->id, 'replied_at' => now()])->save();
            $this->logAction($r, $action, null, $actor);
            $this->audit->log('Reseña propia', $r->code, $action, $before, $text, actor: $actor);
            $this->notifyReply($r);
        });
        if ($r->status === 'publicada') {
            $this->invalidate();
        }

        return $this->show($r->fresh());
    }

    private function transition(OwnReview $r, string $to, string $action, ?string $note, User $actor): array
    {
        $from = $r->status;
        DB::transaction(function () use ($r, $to, $action, $note, $actor, $from) {
            $r->forceFill(['status' => $to, 'moderated_by' => $actor->id, 'moderation_reason' => $to === 'publicada' ? null : $note,
                'published_at' => $to === 'publicada' ? ($r->published_at ?? now()) : $r->published_at])->save();
            $this->logAction($r, $action, $note, $actor);
            $this->audit->log('Reseña propia', $r->code, $action, OwnReview::STATUSES[$from], OwnReview::STATUSES[$to].($note ? " · {$note}" : ''), actor: $actor);
        });
        // OwnReviewPublished / Hidden / Rejected → invalida la caché pública y el feed del widget.
        if ($from === 'publicada' || $to === 'publicada') {
            $this->invalidate();
        }

        return $this->show($r->fresh());
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ApiException::unprocessable('RVW-07', 'RVW-07: indique el motivo (5 a 500 caracteres).');
        }

        return $reason;
    }

    private function logAction(OwnReview $r, string $action, ?string $note, ?User $actor): void
    {
        OwnReviewLog::create(['review_id' => $r->id, 'action' => $action, 'note' => $note, 'user_id' => $actor?->id]);
    }

    // --- envío del pasajero (RVW-02..06) ---

    /** Productos de la reserva y si cada uno ya se puede calificar: viaje completado, o en operación con el servicio completado o pasado. */
    public function reviewable(Reservation $r): Collection
    {
        $today = $this->clock->today()->toDateString();
        $services = BookedService::where('reservation_id', $r->id)->whereNotNull('product_id')->whereNotIn('status', ['cancelado', 'rechazado'])->orderBy('service_date')->get();

        return $services->groupBy('product_id')->map(function ($group, $productId) use ($r, $today) {
            $done = $r->status === Reservation::COMPLETED || ($r->status === Reservation::IN_OPERATION
                && $group->contains(fn (BookedService $s) => $s->status === BookedService::COMPLETED || $s->service_date->toDateString() < $today));

            return ['product_id' => $productId, 'done' => $done, 'date' => $group->max(fn ($s) => $s->service_date->toDateString()), 'services' => $group->pluck('name')->unique()->values()->all()];
        })->values();
    }

    /** Pasajero de la cuenta: el que coincide por correo o nombre; si no, el titular. */
    public function passengerFor(Reservation $r, User $user): ?Passenger
    {
        $pax = Passenger::where('reservation_id', $r->id)->orderBy('seq')->get();

        return $pax->first(fn (Passenger $p) => $p->email && mb_strtolower($p->email) === mb_strtolower((string) $user->email))
            ?? $pax->first(fn (Passenger $p) => mb_strtolower($p->full_name) === mb_strtolower((string) $user->name))
            ?? $pax->firstWhere('is_lead', true);
    }

    /** GET /passenger/reviews/eligibility: aviso «Califica tu viaje», productos por calificar y sus reseñas. */
    public function eligibility(Reservation $r, ?Passenger $pax): array
    {
        $state = $this->state();
        $open = in_array($r->status, [Reservation::COMPLETED, Reservation::IN_OPERATION], true);
        $mine = OwnReview::where('reservation_id', $r->id)->where('passenger_id', $pax?->id)->with('product:id,ref,name')->orderByDesc('created_at')->get();
        $products = Product::whereIn('id', $this->reviewable($r)->pluck('product_id'))->get(['id', 'ref', 'name'])->keyBy('id');
        $items = $this->reviewable($r)->map(function ($it) use ($mine, $products, $open) {
            $review = $mine->firstWhere('product_id', $it['product_id']);
            $p = $products[$it['product_id']];
            $status = match (true) {
                $review !== null => 'calificado',
                ! $this->effective($p) => 'no_disponible',
                ! $open || ! $it['done'] => 'aun_no',
                default => 'pendiente',
            };

            return ['product' => ['ref' => $p->ref, 'name' => $p->name], 'date' => $it['date'], 'services' => $it['services'], 'status' => $status, 'review' => $review?->code];
        })->values();

        return [
            'state' => $state, 'enabled' => $state === 'on', 'reservation' => ['code' => $r->code, 'status' => $r->status, 'travel_to' => $r->travel_to->toDateString()],
            'passenger' => $pax ? ['name' => $pax->full_name] : null, 'items' => $items->all(), 'pending' => $items->where('status', 'pendiente')->count(),
            'subratings' => array_intersect_key(OwnReviewProvisioner::SUBRATINGS, array_flip($this->settings()['active_subratings'])), 'langs' => self::LANGS,
            'reviews' => $mine->map(fn (OwnReview $x) => ['code' => $x->code, 'product' => $x->product?->name, 'stars' => $x->stars, 'title' => $x->title, 'body' => $x->body,
                'status' => $x->status, 'status_label' => OwnReview::PAX_LABELS[$x->status], 'reply' => $x->reply_text, 'replied_at' => $x->replied_at?->toIso8601String(),
                'created_at' => $x->created_at->toIso8601String()])->all(),
        ];
    }

    /**
     * POST /passenger/reviews y /public/reviews/by-token. `photos`: archivos subidos o `[{name, content_base64}]` (hasta 3, 2 MB, JPG/PNG/WebP).
     *
     * @param  array<int, UploadedFile|array>  $photos
     */
    public function submit(Reservation $r, ?Passenger $pax, array $data, array $photos, string $source, ?ReviewRequest $request = null, ?User $actor = null): OwnReview
    {
        $product = Product::where('ref', (string) ($data['product'] ?? ''))->orWhere('id', Str::isUuid($data['product'] ?? null) ? $data['product'] : null)->first()
            ?? throw ApiException::invalid('product', 'Elija el servicio que va a calificar.');
        $this->ensureEffective($product);

        // RVW-02
        if (! in_array($r->status, [Reservation::COMPLETED, Reservation::IN_OPERATION], true)) {
            throw ApiException::unprocessable('RVW-02', 'RVW-02: solo se califica un viaje realizado o en curso; esta reserva está '.(Reservation::STATUS_LABELS[$r->status] ?? $r->status).'.');
        }
        $item = $this->reviewable($r)->firstWhere('product_id', $product->id)
            ?? throw ApiException::unprocessable('RVW-02', 'RVW-02: el servicio no pertenece a su reserva.');
        if (! $item['done']) {
            throw ApiException::unprocessable('RVW-02', 'RVW-02: podrá calificar este servicio cuando se haya realizado.');
        }

        // RVW-04
        $stars = $data['stars'] ?? null;
        if (! is_numeric($stars) || (int) $stars != $stars || $stars < 1 || $stars > 5) {
            throw ApiException::invalid('stars', 'Elija de 1 a 5 estrellas.');
        }
        $settings = $this->settings();
        $subratings = [];
        foreach ((array) ($data['subratings'] ?? []) as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (! in_array($k, $settings['active_subratings'], true)) {
                throw ApiException::invalid("subratings.{$k}", 'Calificación por aspecto no disponible.');
            }
            if (! is_numeric($v) || (int) $v != $v || $v < 1 || $v > 5) {
                throw ApiException::invalid("subratings.{$k}", 'Cada aspecto va de 1 a 5.');
            }
            $subratings[$k] = (int) $v;
        }
        $title = trim((string) ($data['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) {
            throw ApiException::invalid('title', 'El título debe tener entre 3 y 120 caracteres.');
        }
        $body = trim((string) ($data['body'] ?? ''));
        if (mb_strlen($body) < 20 || mb_strlen($body) > 2000) {
            throw ApiException::invalid('body', 'La reseña debe tener entre 20 y 2000 caracteres.');
        }
        $lang = strtolower((string) ($data['lang'] ?? 'es'));
        if (! isset(self::LANGS[$lang])) {
            throw ApiException::invalid('lang', 'Idioma no admitido.');
        }
        $consent = filter_var($data['consent_publish'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // RVW-03
        if (OwnReview::where('reservation_id', $r->id)->where('product_id', $product->id)->where('passenger_id', $pax?->id)->exists()) {
            throw ApiException::conflict('RVW-03', 'RVW-03: ya calificó este servicio de su viaje.');
        }

        // RVW-06: enmascarar antes de evaluar, si así está configurado.
        if ($settings['personal_data'] === 'enmascarar') {
            $title = $this->mask($title);
            $body = $this->mask($body);
        }
        $flags = $this->flags("{$title}\n{$body}", $settings);
        $params = $this->params($product);
        $auto = $consent && $flags === [] && (int) $stars >= $params['minStarsAutoPublish'] && (! $params['moderation'] || $settings['auto_publish']);
        $status = ! $consent ? 'oculta' : ($auto ? 'publicada' : 'pendiente');
        $stored = $this->storePhotos($photos);
        $client = Client::find($r->client_id);
        $name = $pax?->full_name ?? $client?->name ?? 'Pasajero';

        try {
            $review = DB::transaction(function () use ($r, $pax, $product, $item, $stars, $subratings, $title, $body, $lang, $consent, $status, $flags, $stored, $source, $request, $actor, $client, $name, $auto) {
                $review = OwnReview::create([
                    'code' => $this->sequences->next('own_review'), 'reservation_id' => $r->id, 'reservation_code' => $r->code, 'product_id' => $product->id,
                    'client_id' => $r->client_id, 'passenger_id' => $pax?->id, 'author_display' => self::display($name), 'author_name' => $name,
                    'country' => $pax?->nationality ?? $client?->country, 'travel_date' => $item['date'], 'stars' => (int) $stars, 'subratings' => (object) $subratings,
                    'title' => $title, 'body' => $body, 'photos' => $stored, 'lang' => $lang, 'verified' => true, 'consent_publish' => $consent, 'status' => $status,
                    'flags' => $flags, 'moderation_reason' => $status === 'oculta' ? 'Sin autorización de publicación (RVW-05).' : ($flags ? implode('; ', $flags) : null),
                    'source' => $source, 'request_id' => $request?->id, 'published_at' => $auto ? now() : null,
                ]);
                $this->logAction($review, 'crear', $flags ? 'Retenida: '.implode('; ', $flags) : null, $actor);
                if ($auto) {
                    $this->logAction($review, 'autopublicar', "≥ {$this->params($product)['minStarsAutoPublish']} estrellas, sin alertas y con consentimiento", null);
                }
                $this->audit->log('Reseña propia', $review->code, 'crear', null, "{$stars}★ · {$product->ref} · {$r->code} · ".OwnReview::STATUSES[$status], actor: $actor, system: $actor === null);
                if ($request && in_array($request->status, ['programada', 'enviada', 'abierta'], true)) {
                    $request->forceFill(['status' => 'respondida', 'token_hash' => null])->save();
                }

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('RVW-03', 'RVW-03: ya calificó este servicio de su viaje.');
        }
        $this->notifySubmitted($review, $r);
        if ($status === 'publicada') {
            $this->invalidate();
        }

        return $review;
    }

    /** «Thomas Keller» → «Thomas K.» */
    public static function display(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];

        return Str::limit($parts[0].(isset($parts[1]) ? ' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : ''), 80, '');
    }

    /** RVW-06: palabras prohibidas y datos personales (correo, teléfono, pasaporte/DNI) → la reseña queda retenida con el motivo. */
    public function flags(string $text, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $flags = array_map(fn ($kind) => "Dato personal: {$kind}", $this->personalData($text));
        foreach ($settings['banned_words'] as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                $flags[] = "Palabra no permitida: «{$word}»";
            }
        }

        return array_values(array_unique($flags));
    }

    private const PATTERNS = [
        'correo' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu',
        'documento' => '/\b(?:DNI|pasaporte|passport|reisepass|passeport|CE)\b\s*(?:n(?:[°ºo.]|ro\.?|úmero)?\s*)?:?\s*[A-Z]{0,2}\d{6,10}\b/iu',
        'teléfono' => '/(?<![\w])\+?\d(?:[\s().-]?\d){6,14}(?![\w])/u',
    ];

    /** @return list<string> tipos de dato personal encontrados. */
    public function personalData(string $text): array
    {
        $found = [];
        foreach (self::PATTERNS as $kind => $re) {
            if (preg_match($re, $text)) {
                $found[] = $kind;
                $text = (string) preg_replace($re, ' ', $text);
            }
        }

        return $found;
    }

    public function mask(string $text): string
    {
        foreach (self::PATTERNS as $re) {
            $text = (string) preg_replace($re, self::MASK, $text);
        }

        return $text;
    }

    private function storePhotos(array $photos): array
    {
        $photos = array_values(array_filter($photos));
        if (count($photos) > self::PHOTO_MAX) {
            throw ApiException::invalid('photos', 'Puede adjuntar hasta '.self::PHOTO_MAX.' fotos.');
        }
        $out = [];
        $tenant = $this->tenant->id();
        foreach ($photos as $i => $photo) {
            if ($photo instanceof UploadedFile) {
                [$name, $mime, $bytes] = [$photo->getClientOriginalName(), (string) $photo->getMimeType(), (string) file_get_contents($photo->getRealPath())];
            } else {
                $bytes = base64_decode((string) ($photo['content_base64'] ?? ''), true);
                [$name, $mime] = [(string) ($photo['name'] ?? "foto-{$i}"), $bytes !== false ? (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : ''];
            }
            if ($bytes === false || $bytes === '' || ! isset(self::PHOTO_TYPES[$mime])) {
                throw ApiException::invalid('photos', 'Las fotos deben ser JPG, PNG o WebP.');
            }
            if (strlen($bytes) > self::PHOTO_BYTES) {
                throw ApiException::invalid('photos', 'Cada foto puede pesar hasta 2 MB.');
            }
            $path = "own-reviews/{$tenant}/".Str::uuid7().'.'.self::PHOTO_TYPES[$mime];
            Storage::disk('local')->put($path, $bytes);
            $out[] = ['path' => $path, 'name' => Str::limit(basename($name), 120, '')];
        }

        return $out;
    }

    // --- eventos ---

    /** OwnReviewSubmitted → aviso al administrador y al asesor dueño de la cartera. */
    private function notifySubmitted(OwnReview $review, Reservation $r): void
    {
        $owner = Client::whereKey($r->client_id)->value('owner_id') ?? $r->owner_id;
        $to = User::where('status', User::ACTIVE)->whereHas('roles', fn ($q) => $q->where('name', 'tenant_admin'))->get();
        if ($owner && ($advisor = User::find($owner)) && ! $to->contains('id', $advisor->id)) {
            $to->push($advisor);
        }
        $label = OwnReview::STATUSES[$review->status];
        foreach ($to as $u) {
            $this->notify->push('resena.propia.recibida', 'portal', $u, "Nueva reseña {$review->code} · {$review->stars}★ ({$label})",
                "{$review->author_display} calificó {$r->code}: «{$review->title}».".($review->flags ? ' Alertas: '.implode('; ', $review->flags).'.' : ''),
                ['related_type' => 'own_review', 'related_id' => $review->id, 'client_id' => $r->client_id]);
        }
    }

    /** OwnReviewReplied → correo al pasajero y mensaje en su portal (pax_messages / bandeja del portal). */
    private function notifyReply(OwnReview $r): void
    {
        $reservation = $r->reservation_id ? Reservation::find($r->reservation_id) : null;
        $subject = "Respuesta a su reseña «{$r->title}»";
        $body = 'La agencia respondió a su reseña de '.($r->product?->name ?? 'su viaje').":\n\n{$r->reply_text}";
        $data = $reservation ? ['reservation_id' => $reservation->id, 'related_type' => 'reservation', 'related_id' => $reservation->id, 'client_id' => $reservation->client_id] : [];
        $users = $reservation ? User::where('reservation_id', $reservation->id)->get() : collect();
        foreach ($users as $u) {
            $this->notify->push('resena.propia.respuesta', 'portal', $u, $subject, $body, $data);
        }
        $email = $users->first()?->email ?? ($r->passenger_id ? Passenger::whereKey($r->passenger_id)->value('email') : null) ?? Client::whereKey($r->client_id)->value('email');
        if ($email && ! $r->is_demo) {
            $this->notify->push('resena.propia.respuesta', 'email', $email, $subject, $body, $data);
        }
    }

    // --- salida pública (RVW-10) ---

    public function invalidate(): void
    {
        Cache::increment($this->versionKey());
    }

    private function versionKey(): string
    {
        return 'public:reviews:ver:'.$this->tenant->id();
    }

    private function cached(string $key, \Closure $fn): mixed
    {
        return Cache::remember('public:reviews:'.$this->tenant->id().':v'.(int) Cache::get($this->versionKey(), 0).":{$key}", now()->addMinutes(10), $fn);
    }

    /** Promedio y conteo publicados por producto (tarjetas del sitio). Solo productos con la regla efectiva y `show_on_site`. */
    public function summaries(string $channel = 'site'): array
    {
        $memo = $channel.':'.(int) Cache::get($this->versionKey(), 0).':'.$this->rules->version();
        if (isset($this->summaryMemo[$memo])) {
            return $this->summaryMemo[$memo];
        }

        return $this->summaryMemo[$memo] = $this->computeSummaries($channel);
    }

    private function computeSummaries(string $channel): array
    {
        if (! $this->settings()[$channel === 'widget' ? 'show_on_widget' : 'show_on_site'] || ! $this->rules->planAllows(self::RULE) || ! $this->rules->tenantOn(self::RULE)) {
            return [];
        }
        $rows = $this->cached('summaries', fn () => OwnReview::where('status', 'publicada')->groupBy('product_id')
            ->selectRaw('product_id, round(avg(stars)::numeric, 1) as avg, count(*) as n, bool_or(is_demo) as demo')->get()
            ->mapWithKeys(fn ($x) => [$x->product_id => ['avg' => (string) $x->avg, 'count' => (int) $x->n, 'demo' => (bool) $x->demo]])->all());

        return array_filter($rows, fn ($v, $productId) => $this->effective($productId), ARRAY_FILTER_USE_BOTH);
    }

    /** Bloque «Reseñas de viajeros verificados» de la ficha: promedio, distribución, aspectos y reseñas (filtro por estrellas, página de 5). */
    public function productBlock(Product $p, ?int $stars = null, int $page = 1, ?string $lang = null, string $channel = 'site'): ?array
    {
        if (! ($this->summaries($channel)[$p->id] ?? null)) {
            return null;
        }

        return $this->cached("product:{$p->id}:".($stars ?? 0).":{$page}:".($lang ?? '*'), function () use ($p, $stars, $page, $lang) {
            $published = OwnReview::where('product_id', $p->id)->where('status', 'publicada');
            $all = (clone $published)->get(['stars', 'subratings', 'is_demo']);
            $dist = [];
            foreach ([5, 4, 3, 2, 1] as $n) {
                $dist[$n] = $all->where('stars', $n)->count();
            }
            $subs = [];
            foreach (OwnReviewProvisioner::SUBRATINGS as $k => $label) {
                $vals = $all->map(fn ($r) => $r->subratings[$k] ?? null)->filter();
                if ($vals->isNotEmpty()) {
                    $subs[] = ['key' => $k, 'label' => $label, 'avg' => round($vals->avg(), 1)];
                }
            }
            $filtered = (clone $published)->when($stars, fn ($q) => $q->where('stars', $stars))->when($lang, fn ($q) => $q->where('lang', $lang));
            $total = (clone $filtered)->count();
            $per = 5;
            $page = max(1, min($page, (int) max(1, ceil($total / $per))));
            $items = $filtered->orderByDesc('published_at')->offset(($page - 1) * $per)->limit($per)->get();

            return [
                'average' => round($all->avg('stars'), 1), 'count' => $all->count(), 'distribution' => $dist, 'subratings' => $subs, 'is_demo' => $all->contains('is_demo', true),
                'filter' => ['stars' => $stars, 'lang' => $lang], 'total' => $total, 'page' => $page, 'pages' => (int) max(1, ceil($total / $per)),
                'items' => $items->map(fn (OwnReview $r) => $this->publicItem($r))->all(),
            ];
        });
    }

    public function publicItem(OwnReview $r): array
    {
        return ['code' => $r->code, 'author' => $r->author_display, 'country' => $r->country, 'travel_date' => $r->travel_date->format('Y-m'), 'stars' => $r->stars,
            'title' => $r->title, 'body' => $r->body, 'lang' => $r->lang, 'subratings' => (object) ($r->subratings ?? []), 'reply' => $r->reply_text,
            'published_at' => $r->published_at?->toDateString(), 'verified' => $r->verified, 'is_demo' => $r->is_demo];
    }

    /** GET /public/reviews/verified?product=&stars=&lang=&page= (sin producto: las últimas de todo el sitio). */
    public function publicList(array $q, string $channel = 'site'): array
    {
        $ref = (string) ($q['product'] ?? '');
        $stars = isset($q['stars']) && (int) $q['stars'] >= 1 && (int) $q['stars'] <= 5 ? (int) $q['stars'] : null;
        $lang = isset($q['lang'], self::LANGS[$q['lang']]) ? $q['lang'] : null;
        if ($ref !== '') {
            $p = Product::where('ref', $ref)->orWhere('code', $ref)->first();

            return ['product' => $ref, 'block' => $p ? $this->productBlock($p, $stars, max(1, (int) ($q['page'] ?? 1)), $lang, $channel) : null];
        }

        return ['product' => null, 'items' => $this->latest(max(1, min(12, (int) ($q['limit'] ?? 3))), $channel)];
    }

    /** Home: «Opiniones de viajeros verificados» (las últimas publicadas). */
    public function latest(int $limit = 3, string $channel = 'site'): array
    {
        $ids = array_keys($this->summaries($channel));
        if ($ids === []) {
            return [];
        }

        return $this->cached("latest:{$limit}", fn () => OwnReview::where('status', 'publicada')->whereIn('product_id', $ids)->with('product:id,ref,name')
            ->orderByDesc('published_at')->limit($limit)->get()->map(fn (OwnReview $r) => $this->publicItem($r) + ['product' => $r->product?->name])->all());
    }

    /** §11: aggregateRating y hasta 5 Review del JSON-LD, solo con reseñas propias publicadas. */
    public function jsonLd(Product $p): array
    {
        $block = $this->productBlock($p);
        if (! $block) {
            return [];
        }
        $reviews = OwnReview::where('product_id', $p->id)->where('status', 'publicada')->orderByDesc('published_at')->limit(5)->get();

        return [
            'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => $block['average'], 'reviewCount' => $block['count'], 'bestRating' => 5, 'worstRating' => 1],
            'review' => $reviews->map(fn (OwnReview $r) => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r->author_display],
                'datePublished' => $r->published_at?->toDateString(), 'name' => $r->title, 'reviewBody' => $r->body, 'inLanguage' => $r->lang,
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $r->stars, 'bestRating' => 5, 'worstRating' => 1]])->all(),
        ];
    }

    /** POST /reviews/export-feed: regenera el feed del widget/sitio con lo publicado y deja constancia (§9 feed/exportar_widget). */
    public function exportFeed(User $actor): array
    {
        $this->ensureEffective();
        $items = OwnReview::where('status', 'publicada')->with('product:id,ref,name')->orderByDesc('published_at')->get()
            ->filter(fn (OwnReview $r) => $this->effective($r->product_id))->values();
        $settings = $this->settings();
        $settings['last_export'] = ['at' => now()->toIso8601String(), 'by' => $actor->name, 'count' => $items->count()];
        $row = Setting::where('section', 'own_reviews')->first();
        $row ? $row->forceFill(['values' => $settings])->save() : Setting::create(['section' => 'own_reviews', 'values' => $settings]);
        $this->invalidate();
        $this->audit->log('Reseñas propias', 'feed', 'feed/exportar_widget', null, "{$items->count()} reseña(s) publicadas", actor: $actor);

        return ['message' => "Feed regenerado: {$items->count()} reseña(s) publicadas", 'last_export' => $settings['last_export'], 'site' => $settings['show_on_site'], 'widget' => $settings['show_on_widget'],
            'items' => $items->map(fn (OwnReview $r) => $this->publicItem($r) + ['product' => $r->product?->ref])->all()];
    }
}
