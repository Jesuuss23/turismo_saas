<?php

namespace App\Services\Experience;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Crm\Client;
use App\Models\Experience\OwnReview;
use App\Models\Experience\ReviewRequest;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solicitudes de reseña postviaje (spec 18 §3.3, §4, RVW-09): `daysAfter` días después de travel_to a las 09:00 (zona del tenant),
 * a todos los pasajeros elegibles y sin incentivos; recordatorio y vencimiento configurables. Enlace firmado de un solo uso.
 */
class ReviewRequestService
{
    public function __construct(
        private readonly OwnReviewService $reviews,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly CrmClock $clock,
        private readonly Notify $notify,
        private readonly TenantContext $tenant,
    ) {}

    public function list(array $f, User $user): array
    {
        $q = ReviewRequest::with(['reservation:id,code,title,travel_to,owner_id', 'client:id,code,name,owner_id']);
        if ($this->reviews->portfolioOnly($user)) {
            $q->where(fn ($w) => $w->whereIn('client_id', Client::where('owner_id', $user->id)->select('id'))
                ->orWhereIn('reservation_id', Reservation::where('owner_id', $user->id)->select('id')));
        }
        if (($s = $f['status'] ?? null) && isset(ReviewRequest::STATUSES[$s])) {
            $q->where('status', $s);
        }

        return $q->orderByRaw("CASE status WHEN 'programada' THEN 0 WHEN 'enviada' THEN 1 WHEN 'abierta' THEN 2 ELSE 3 END")->orderBy('scheduled_at')->limit(300)->get()
            ->map(fn (ReviewRequest $r) => $this->present($r))->all();
    }

    public function present(ReviewRequest $r): array
    {
        return [
            'id' => $r->id, 'code' => $r->code, 'status' => $r->status, 'status_label' => ReviewRequest::STATUSES[$r->status], 'channel' => $r->channel, 'lang' => $r->lang,
            'to' => $r->to_address, 'reservation' => $r->reservation ? ['code' => $r->reservation->code, 'title' => $r->reservation->title, 'travel_to' => $r->reservation->travel_to->toDateString()] : null,
            'client' => $r->client ? ['code' => $r->client->code, 'name' => $r->client->name] : null,
            'scheduled_at' => $r->scheduled_at->toIso8601String(), 'sent_at' => $r->sent_at?->toIso8601String(), 'opened_at' => $r->opened_at?->toIso8601String(),
            'reminded_at' => $r->reminded_at?->toIso8601String(), 'expires_at' => $r->expires_at?->toIso8601String(), 'is_demo' => $r->is_demo, 'lock_version' => $r->lock_version,
        ];
    }

    /** RVW-09: travel_to + daysAfter a las 09:00 de la zona del tenant. */
    public function dueAt(Reservation $r): Carbon
    {
        $days = $this->reviews->params()['daysAfter'];

        return Carbon::parse($r->travel_to->toDateString().' 09:00:00', $this->clock->timezone())->addDays($days)->utc();
    }

    /** ReservationCompleted → ScheduleReviewRequest (idempotente: una solicitud por reserva). */
    public function scheduleFor(Reservation $r, ?User $actor = null, bool $demo = false): ?ReviewRequest
    {
        if (! $this->reviews->effective() || $r->status !== Reservation::COMPLETED || ReviewRequest::withTrashed()->where('reservation_id', $r->id)->exists()) {
            return null;
        }
        $client = Client::find($r->client_id);
        $lead = $r->passengers()->where('is_lead', true)->first();
        $to = $lead?->email ?: $client?->email;
        if (! $to) {
            return null;
        }
        $settings = $this->reviews->settings();
        $at = $this->dueAt($r);
        $req = ReviewRequest::create([
            'code' => $this->sequences->next('review_request'), 'reservation_id' => $r->id, 'client_id' => $r->client_id, 'to_address' => Str::limit($to, 160, ''),
            'channel' => $settings['invite']['channel'], 'lang' => $this->langFor($client), 'scheduled_at' => $at,
            'expires_at' => $at->copy()->addDays($settings['invite']['expire_days']), 'status' => 'programada', 'is_demo' => $demo,
        ]);
        $this->audit->log('Solicitud de reseña', $req->code, 'programar', null, "{$r->code} · {$req->to_address} · ".$at->copy()->setTimezone($this->clock->timezone())->format('d/m/Y H:i'),
            actor: $actor, system: $actor === null);

        return $req;
    }

    /** «Programar invitaciones pendientes»: reservas completadas sin solicitud. */
    public function schedulePending(User $actor): array
    {
        $this->reviews->ensureEffective();
        $created = [];
        $have = ReviewRequest::withTrashed()->pluck('reservation_id');
        foreach (Reservation::where('status', Reservation::COMPLETED)->whereNotIn('id', $have)->orderBy('travel_to')->get() as $r) {
            if ($req = $this->scheduleFor($r, $actor)) {
                $created[] = $req->code;
            }
        }

        return ['message' => count($created).' invitación(es) programada(s)', 'scheduled' => count($created), 'codes' => $created];
    }

    /** «Enviar ahora» (programada) y «Reenviar» (enviada/abierta/vencida): genera un enlace nuevo; el anterior deja de valer. */
    public function send(ReviewRequest $req, ?User $actor = null): array
    {
        $this->reviews->ensureEffective();
        if (! in_array($req->status, ['programada', 'enviada', 'abierta', 'vencida'], true)) {
            throw ApiException::unprocessable('SRV-STATE', 'Solo se envían solicitudes programadas, enviadas, abiertas o vencidas.');
        }
        $resend = $req->status !== 'programada';
        $token = Str::random(48);
        $settings = $this->reviews->settings();
        DB::transaction(function () use ($req, $token, $settings, $resend, $actor) {
            $req->forceFill(['token_hash' => hash('sha256', $token), 'status' => 'enviada', 'sent_at' => now(), 'opened_at' => null,
                'expires_at' => now()->addDays($settings['invite']['expire_days'])])->save();
            $this->audit->log('Solicitud de reseña', $req->code, $resend ? 'reenviar' : 'enviar', null, "{$req->channel} · {$req->to_address}", actor: $actor, system: $actor === null);
        });
        [$subject, $body] = $this->render($req, $token, $settings);
        $this->notify->push('resena.propia.invitacion', $req->channel, $req->to_address, $subject, $body,
            ['related_type' => 'reservation', 'related_id' => $req->reservation_id, 'client_id' => $req->client_id, 'reservation_id' => $req->reservation_id]);

        return $this->present($req->fresh()) + (app()->isProduction() ? [] : ['dev_link' => $this->link($req, $token)]);
    }

    public function cancel(ReviewRequest $req, User $actor): array
    {
        if ($req->status !== 'programada') {
            throw ApiException::unprocessable('SRV-STATE', 'Solo se cancela una solicitud programada.');
        }
        $req->forceFill(['status' => 'cancelada', 'token_hash' => null])->save();
        $this->audit->log('Solicitud de reseña', $req->code, 'cancelar', 'programada', 'cancelada', actor: $actor);

        return $this->present($req->fresh());
    }

    /** Vista previa del mensaje (sin enlace real). */
    public function preview(ReviewRequest $req): array
    {
        [$subject, $body] = $this->render($req, 'VISTA-PREVIA', $this->reviews->settings());

        return ['subject' => $subject, 'body' => $body, 'channel' => $req->channel, 'to' => $req->to_address];
    }

    /** SendDueReviewRequests (cada 15 min): envía las programadas vencidas, recuerda una vez y vence las no respondidas. */
    public function dispatchDue(): array
    {
        if (! $this->reviews->effective()) {
            return ['sent' => 0, 'reminded' => 0, 'expired' => 0, 'skipped' => 'R-OWN-REVIEWS inactiva'];
        }
        $sent = $reminded = $expired = 0;
        foreach (ReviewRequest::where('status', 'programada')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(200)->get() as $req) {
            $this->send($req);
            $sent++;
        }
        $settings = $this->reviews->settings();
        $days = (int) $settings['invite']['reminder_days'];
        foreach (ReviewRequest::whereIn('status', ['enviada', 'abierta'])->whereNull('reminded_at')->where('expires_at', '>', now())->get() as $req) {
            if ($days > 0 && $req->sent_at && $req->sent_at->lte(now()->subDays($days))) {
                $req->forceFill(['reminded_at' => now()])->save();
                $this->notify->push('resena.propia.recordatorio', $req->channel, $req->to_address, 'Recordatorio: '.$this->render($req, 'enlace enviado', $settings)[0],
                    'Le recordamos que puede calificar su viaje con el enlace que le enviamos. Si ya lo hizo, gracias.', ['related_type' => 'reservation', 'related_id' => $req->reservation_id]);
                $reminded++;
            }
        }
        foreach (ReviewRequest::whereIn('status', ['enviada', 'abierta'])->where('expires_at', '<=', now())->get() as $req) {
            $req->forceFill(['status' => 'vencida', 'token_hash' => null])->save();
            $this->audit->log('Solicitud de reseña', $req->code, 'vencer', null, 'sin respuesta', system: true);
            $expired++;
        }

        return ['sent' => $sent, 'reminded' => $reminded, 'expired' => $expired];
    }

    // --- formulario público por enlace ---

    public function byToken(string $code, string $token): ReviewRequest
    {
        $req = ReviewRequest::where('code', $code)->first();
        if (! $req || ! $req->token_hash || ! hash_equals($req->token_hash, hash('sha256', $token)) || ! in_array($req->status, ['enviada', 'abierta'], true)
            || ($req->expires_at && $req->expires_at->isPast())) {
            throw ApiException::unprocessable('SRV-TOKEN', 'El enlace no es válido, ya se usó o venció. Pida uno nuevo a la agencia.');
        }

        return $req;
    }

    /** GET del formulario: marca «abierta» y devuelve lo que se puede calificar. */
    public function open(string $code, string $token): array
    {
        $req = $this->byToken($code, $token);
        if ($req->status === 'enviada') {
            $req->forceFill(['status' => 'abierta', 'opened_at' => now()])->save();
        }
        $r = Reservation::findOrFail($req->reservation_id);
        $lead = $r->passengers()->where('is_lead', true)->first();

        return ['request' => $req->code, 'agency' => $this->tenant->tenant()?->trade_name] + $this->reviews->eligibility($r, $lead);
    }

    public function submit(string $code, string $token, array $data, array $photos): array
    {
        $req = $this->byToken($code, $token);
        $r = Reservation::findOrFail($req->reservation_id);
        $lead = $r->passengers()->where('is_lead', true)->first();
        $review = $this->reviews->submit($r, $lead, $data, $photos, 'email', $req);

        return ['code' => $review->code, 'status' => $review->status, 'status_label' => OwnReview::PAX_LABELS[$review->status]];
    }

    private function link(ReviewRequest $req, string $token): string
    {
        return rtrim((string) config('turismo.frontend_url'), '/')."/sitio/resena/{$req->code}?t={$token}";
    }

    private function render(ReviewRequest $req, string $token, array $settings): array
    {
        $r = $req->reservation ?? Reservation::find($req->reservation_id);
        $product = $r ? Product::whereIn('id', $r->services()->whereNotNull('product_id')->select('product_id'))->value('name') : null;
        $vars = ['{nombre}' => Str::before(Client::whereKey($req->client_id)->value('name') ?? '', ' ') ?: 'viajero', '{agencia}' => $this->tenant->tenant()?->trade_name ?? 'la agencia',
            '{producto}' => $product ?? ($r?->title ?? 'su viaje'), '{enlace}' => $token === 'VISTA-PREVIA' ? '[enlace de un solo uso]' : $this->link($req, $token), '{reserva}' => $r?->code ?? ''];

        return [strtr($settings['invite']['subject'], $vars), strtr($settings['invite']['body'], $vars)];
    }

    private function langFor(?Client $client): string
    {
        return in_array($client?->country, ['Estados Unidos', 'Reino Unido', 'Canadá', 'Australia'], true) ? 'en' : 'es';
    }
}
