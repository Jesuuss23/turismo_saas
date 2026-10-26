<?php

namespace App\Services\Quotes;

use App\Events\Quotes\QuoteApproved;
use App\Events\Quotes\QuoteExpired;
use App\Events\Quotes\QuoteInternalApprovalDecided;
use App\Events\Quotes\QuoteInternalApprovalRequested;
use App\Events\Quotes\QuoteNegotiationRecorded;
use App\Events\Quotes\QuoteRejected;
use App\Events\Quotes\QuoteSent;
use App\Events\Quotes\QuoteViewed;
use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Models\Quotes\InternalApproval;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteAccessToken;
use App\Models\Quotes\QuoteNegotiation;
use App\Models\Quotes\QuoteSend;
use App\Models\Quotes\QuoteVersion;
use App\Models\Quotes\QuoteView;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Crm\CrmUsers;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclo comercial de la cotización (§4): aprobación interna, enlace, envío, vista, negociación,
 * aprobación/rechazo del cliente y vencimiento (JOB-02).
 */
class QuoteWorkflow
{
    public const EVIDENCE = [
        'Aceptación en portal (enlace con token)', 'Correo electrónico del cliente', 'Mensaje de WhatsApp',
        'Firma de conformidad', 'Orden de compra / servicio', 'Portal cliente',
    ];

    public const CHANNELS = ['Email', 'WhatsApp', 'Portal'];

    public function __construct(
        private readonly QuoteAccess $access,
        private readonly QuoteService $quotes,
        private readonly QuotePricing $pricing,
        private readonly CrmClock $clock,
        private readonly CrmUsers $users,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    // ── Aprobación interna (§4.2) ───────────────────────────────────────

    /** QTE-BR-15: el dueño solicita con justificación; una sola pendiente por versión. */
    public function requestApproval(Quote $quote, string $note, User $actor): InternalApproval
    {
        if (! $this->access->canRequestApproval($quote, $actor)) {
            throw ApiException::forbidden('QTE-BR-15', 'Solo el asesor dueño de la cotización solicita la aprobación');
        }
        $version = $this->draftVersion($quote);
        $state = QuotePolicy::state($version);
        if ($state === 'no_requerida' || $state === 'aprobada') {
            throw ApiException::rule('QTE-BR-11', $state === 'aprobada' ? 'La versión ya tiene aprobación interna' : 'La versión está dentro de la política comercial; no requiere aprobación');
        }
        if ($state === 'pendiente') {
            throw ApiException::rule('QTE-BR-15', 'Ya hay una solicitud de aprobación pendiente para esta versión');
        }

        return DB::transaction(function () use ($quote, $version, $note, $actor) {
            $approval = InternalApproval::create([
                'quote_version_id' => $version->id,
                'reasons' => QuotePolicy::versionReasons($version),
                'over_cap' => QuotePolicy::overCap((string) $version->discount_pct),
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'request_note' => $note,
                'status' => InternalApproval::PENDING,
            ]);
            $quote->touch();
            $this->audit->log('Cotización', $quote->code, 'solicitar_aprobacion_interna', implode('; ', $approval->reasons), "v{$version->version_no} · {$note}", actor: $actor);
            foreach ($this->users->managers() as $manager) {
                $this->notify->push('cotizacion.aprobacion_solicitada', 'portal', $manager, "Aprobación solicitada: {$quote->code}",
                    "{$actor->name} solicita aprobar {$quote->code} v{$version->version_no} (".implode('; ', $approval->reasons).')',
                    ['client_id' => $quote->client_id, 'related_type' => 'quote', 'related_id' => $quote->id]);
            }
            QuoteInternalApprovalRequested::dispatch($quote->id, $quote->opportunity_id);

            return $approval;
        });
    }

    /** Aprobar/rechazar (QTE-BR-14: descuento > 10 % exige además discount_override). Justificación obligatoria. */
    public function decideApproval(Quote $quote, QuoteVersion $version, string $decision, string $note, User $actor): InternalApproval
    {
        $this->access->require($actor, QuoteAccess::APPROVE_INTERNAL, 'aprobar cotizaciones internamente');
        if ($version->id !== $quote->current_version_id || ! in_array($version->status, [QuoteVersion::DRAFT, QuoteVersion::SENT], true)) {
            throw ApiException::rule('QTE-BR-19', 'Solo se decide la aprobación de la versión vigente en borrador o enviada');
        }
        $overCap = QuotePolicy::overCap((string) $version->discount_pct);
        if ($decision === 'approve' && $overCap && ! $actor->hasPermissionTo(QuoteAccess::DISCOUNT_OVERRIDE)) {
            throw ApiException::forbidden('QTE-BR-14', 'Aprobar un descuento mayor a '.QuotePolicy::MAX_DISCOUNT.' % requiere quotes.discount_override', [QuoteAccess::DISCOUNT_OVERRIDE]);
        }
        if ($decision === 'approve' && QuotePolicy::state($version) === 'aprobada') {
            throw ApiException::rule('QTE-BR-11', 'La versión ya tiene aprobación interna');
        }

        return DB::transaction(function () use ($quote, $version, $decision, $note, $actor, $overCap) {
            $approval = InternalApproval::where('quote_version_id', $version->id)->where('status', InternalApproval::PENDING)->lockForUpdate()->first()
                ?? new InternalApproval([
                    'quote_version_id' => $version->id, 'reasons' => QuotePolicy::versionReasons($version), 'over_cap' => $overCap,
                ]);
            $approved = $decision === 'approve';
            $approval->fill([
                'status' => $approved ? InternalApproval::APPROVED : InternalApproval::REJECTED,
                'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'is_override' => $approved,
            ])->save();
            if ($approved) {
                $version->forceFill(['internal_approval_id' => $approval->id])->save();
            }
            $quote->touch();

            $this->audit->log('Cotización', $quote->code, $approved ? 'aprobacion_interna' : 'rechazar_aprobacion_interna', implode('; ', $approval->reasons),
                "v{$version->version_no} · ".($approved ? 'override de política' : 'rechazada')." · {$note}", actor: $actor);
            if ($quote->owner_id !== $actor->id && $quote->owner) {
                $this->notify->push('cotizacion.aprobacion_'.($approved ? 'aprobada' : 'rechazada'), 'portal', $quote->owner,
                    "{$quote->code}: aprobación interna ".($approved ? 'aprobada' : 'rechazada'), $note,
                    ['client_id' => $quote->client_id, 'related_type' => 'quote', 'related_id' => $quote->id]);
            }
            QuoteInternalApprovalDecided::dispatch($quote->id, $quote->opportunity_id);

            return $approval;
        });
    }

    // ── Enlace, envío y vista (PROC-023) ────────────────────────────────

    public function activeToken(Quote $quote): ?QuoteAccessToken
    {
        return $quote->tokens()->whereNull('revoked_at')->first();
    }

    public static function portalUrl(string $token): string
    {
        return rtrim((string) config('turismo.frontend_url'), '/').'/c/'.$token;
    }

    /** Genera o regenera el enlace (revoca el anterior). Devuelve [registro, token en claro]. */
    public function token(Quote $quote, User $actor, bool $audit = true): array
    {
        return DB::transaction(function () use ($quote, $actor, $audit) {
            $previous = $this->activeToken($quote);
            $previous?->forceFill(['revoked_at' => now()])->save();
            $plain = Str::random(40);
            $token = QuoteAccessToken::create([
                'quote_id' => $quote->id, 'token_hash' => QuoteAccessToken::hash($plain), 'token_cipher' => encrypt($plain),
                'created_by' => $actor->id, 'created_at' => now(), 'expires_at' => $quote->valid_until->toDateString(),
            ]);
            if ($audit) {
                $this->audit->log('Cotización', $quote->code, $previous ? 'regenerar_token' : 'generar_token', $previous ? 'enlace anterior revocado' : null, 'enlace vigente hasta '.$quote->valid_until->toDateString(), actor: $actor);
            }

            return [$token, $plain];
        });
    }

    /** §4.1 borrador → enviada (o reenvío). QTE-BR-12/16/23/24. */
    public function send(Quote $quote, array $channels, ?string $message, User $actor): Quote
    {
        $today = $this->clock->today();
        if (in_array($quote->status, [Quote::APPROVED, Quote::CONVERTED, Quote::REJECTED, Quote::CANCELLED], true)) {
            throw ApiException::rule('QTE-BR-23', 'No se reenvía una cotización '.mb_strtolower(Quote::STATUS_LABELS[$quote->status]));
        }
        if ($quote->isExpired($today)) {
            throw ApiException::rule('QTE-BR-16', 'Cotización vencida: cree una nueva versión para volver a enviarla');
        }
        $version = $quote->currentVersion()->withCount('items')->firstOrFail();
        if ($version->items_count === 0) {
            throw ApiException::rule('QTE-BR-04', 'La versión no tiene ítems');
        }
        if (QuotePolicy::blocks($version)) {
            throw ApiException::rule('QTE-BR-12', QuotePolicy::blockedMessage($version));
        }
        $client = $quote->client;
        $recipients = [];
        foreach (array_values(array_unique($channels)) as $channel) {
            $recipients[$channel] = match ($channel) {
                'Email' => $client->email ?? $client->primaryContact?->email,
                'WhatsApp' => $client->phone ?? $client->primaryContact?->phone,
                default => $client->name,
            } ?? throw ApiException::invalid('channels', $channel === 'Email' ? 'El cliente no tiene correo registrado' : 'El cliente no tiene teléfono registrado');
        }

        return DB::transaction(function () use ($quote, $version, $recipients, $message, $actor, $today) {
            $first = $quote->status === Quote::DRAFT;
            $before = $quote->status;
            if ($first && $quote->valid_until->lt($today)) {
                $quote->valid_until = $today->copy()->addDays($this->pricing->validityDays())->toDateString();
            }
            $token = $this->activeToken($quote);
            $plain = $token?->plain() ?? $this->token($quote, $actor, false)[1];
            if ($first) {
                $version->forceFill(['status' => QuoteVersion::SENT, 'frozen_at' => now(), 'content_hash' => self::contentHash($version)])->save();
                $quote->status = Quote::SENT;
            }
            $quote->save();
            $this->activeToken($quote)?->forceFill(['expires_at' => $quote->valid_until->toDateString()])->save();

            $url = self::portalUrl($plain);
            $customer = User::where('client_id', $quote->client_id)->role('customer')->where('status', User::ACTIVE)->first();
            foreach ($recipients as $channel => $recipient) {
                QuoteSend::create([
                    'quote_id' => $quote->id, 'version_no' => $version->version_no, 'channel' => $channel, 'recipient' => $recipient,
                    'message' => $message, 'sent_by' => $actor->id, 'sent_at' => now(),
                ]);
                $this->notify->push('cotizacion.enviada', strtolower($channel), $channel === 'Portal' && $customer ? $customer : $recipient,
                    "Cotización {$quote->code}: {$quote->title}", trim(($message ? "{$message}\n\n" : '')."Revise y apruebe su cotización aquí: {$url}"),
                    ['client_id' => $quote->client_id, 'related_type' => 'quote', 'related_id' => $quote->id]);
            }

            $this->audit->log('Cotización', $quote->code, 'enviar', Quote::STATUS_LABELS[$before],
                Quote::STATUS_LABELS[$quote->status]." · v{$version->version_no} · ".implode(', ', array_keys($recipients)), actor: $actor);
            QuoteSent::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /** QTE-BR-22: requiere enlace y estado ≠ borrador; solo `enviada` pasa a `vista`. */
    public function registerView(Quote $quote, string $source, ?User $actor = null, ?string $ip = null, ?string $userAgent = null): Quote
    {
        if ($quote->status === Quote::DRAFT || $this->activeToken($quote) === null) {
            throw ApiException::rule('QTE-BR-22', 'Solo se registra la vista de una cotización enviada con enlace generado');
        }

        return DB::transaction(function () use ($quote, $source, $actor, $ip, $userAgent) {
            QuoteView::create([
                'quote_id' => $quote->id, 'version_no' => $quote->current_version_no, 'viewed_at' => now(), 'source' => $source,
                'ip' => $ip, 'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null, 'recorded_by' => $actor?->id,
            ]);
            $before = $quote->status;
            $quote->viewed_at = now();
            if ($quote->status === Quote::SENT) {
                $quote->status = Quote::VIEWED;
            }
            $quote->save();
            $this->audit->log('Cotización', $quote->code, $source === 'portal' ? 'vista_portal' : 'registrar_vista', Quote::STATUS_LABELS[$before],
                Quote::STATUS_LABELS[$quote->status].($ip ? " · IP {$ip}" : ''), actor: $actor, system: $actor === null);
            if ($quote->owner) {
                $this->notify->push('cotizacion.vista', 'portal', $quote->owner, "{$quote->code} fue vista por el cliente", $quote->title,
                    ['client_id' => $quote->client_id, 'related_type' => 'quote', 'related_id' => $quote->id]);
            }
            QuoteViewed::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /** §4.1 → negociación; la oportunidad pasa a `negociacion`; opcionalmente crea nueva versión. */
    public function negotiate(Quote $quote, string $channel, string $note, bool $createVersion, User $actor): Quote
    {
        if (! in_array($quote->status, Quote::FOLLOW_UP, true)) {
            throw ApiException::rule('QTE-BR-21', 'Solo se negocia una cotización enviada, vista o en negociación');
        }
        if ($quote->isExpired($this->clock->today())) {
            throw ApiException::rule('QTE-BR-16', 'Cotización vencida: cree una nueva versión');
        }

        return DB::transaction(function () use ($quote, $channel, $note, $createVersion, $actor) {
            $before = $quote->status;
            $quote->forceFill(['status' => Quote::NEGOTIATION])->save();
            $negotiation = QuoteNegotiation::create(['quote_id' => $quote->id, 'channel' => $channel, 'note' => $note, 'created_by' => $actor->id, 'created_at' => now()]);
            $this->audit->log('Cotización', $quote->code, 'negociacion', Quote::STATUS_LABELS[$before], "{$channel} · {$note}", actor: $actor);
            QuoteNegotiationRecorded::dispatch($quote->id, $quote->opportunity_id);
            if ($createVersion) {
                $quote = $this->quotes->newVersion($quote->fresh(), $actor, 'por negociación');
                $negotiation->forceFill(['created_version_no' => $quote->current_version_no])->save();
            }

            return $quote->fresh();
        });
    }

    // ── Respuesta del cliente (PROC-024) ────────────────────────────────

    /**
     * Aprobación del cliente registrada por un interno (`reservations.create`) o por el cliente en el portal.
     * QTE-BR-13/16/21. `$evidence`: approved_by_name, channel, ref, ip?, internal_note?.
     */
    public function approve(Quote $quote, array $evidence, ?User $actor, bool $portal = false): Quote
    {
        $today = $this->clock->today();
        if (! in_array($quote->status, Quote::FOLLOW_UP, true)) {
            throw ApiException::rule('QTE-BR-21', 'Solo se aprueba una cotización enviada, vista o en negociación');
        }
        if ($quote->isExpired($today)) {
            throw ApiException::rule('QTE-BR-16', 'Cotización vencida: no se puede aprobar. Cree una nueva versión');
        }
        $version = $quote->currentVersion()->firstOrFail();
        if (QuotePolicy::blocks($version)) {
            $inline = $evidence['internal_note'] ?? null;
            if ($portal || ! $inline || ! $actor?->hasPermissionTo(QuoteAccess::APPROVE_INTERNAL)) {
                throw ApiException::rule('QTE-BR-13', $portal
                    ? 'La cotización está en revisión interna; su asesor le confirmará cuando pueda aprobarla'
                    : QuotePolicy::blockedMessage($version).'. Registre la aprobación interna en el mismo paso o solicítela a gerencia');
            }
        }

        return DB::transaction(function () use ($quote, $version, $evidence, $actor, $portal) {
            if (QuotePolicy::blocks($version)) {
                $this->decideApproval($quote, $version, 'approve', $evidence['internal_note'], $actor);
                $quote->refresh();
            }
            $before = $quote->status;
            $version->forceFill(['status' => QuoteVersion::APPROVED])->save();
            $quote->forceFill([
                'status' => Quote::APPROVED, 'approved_at' => now(), 'approved_by_name' => $evidence['approved_by_name'],
                'approval_channel' => $evidence['channel'], 'approval_ref' => $evidence['ref'] ?? null,
                'approval_recorded_by' => $portal ? null : $actor?->id, 'approval_ip' => $evidence['ip'] ?? null,
                'approval_hash' => $portal ? 'sha256:'.($version->content_hash ?? self::contentHash($version)) : null,
                'approval_version_no' => $version->version_no, 'rejected_at' => null, 'rejected_reason' => null,
            ])->save();

            $this->audit->log('Cotización', $quote->code, $portal ? 'aprobar_portal' : 'aprobar', Quote::STATUS_LABELS[$before],
                "v{$version->version_no} · {$evidence['approved_by_name']} · {$evidence['channel']}".(! empty($evidence['ref']) ? " · {$evidence['ref']}" : '').(! empty($evidence['ip']) ? " · IP {$evidence['ip']}" : ''),
                actor: $portal ? null : $actor, system: $portal && $actor === null);
            $data = ['client_id' => $quote->client_id, 'related_type' => 'quote', 'related_id' => $quote->id];
            if ($quote->owner) {
                $this->notify->push('cotizacion.aprobada', 'portal', $quote->owner, "{$quote->code} aprobada por el cliente", $quote->title, $data);
            }
            if ($email = $quote->client?->email) {
                $this->notify->push('cotizacion.aprobada', 'email', $email, "Confirmamos su aprobación de {$quote->code}",
                    "Gracias, registramos la aprobación de la versión {$version->version_no}. Su asesor coordinará la reserva.", $data);
            }
            QuoteApproved::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /** QTE-BR-17: motivo obligatorio; desde enviada/vista/negociación/vencida. */
    public function reject(Quote $quote, string $reason, ?User $actor, bool $portal = false): Quote
    {
        $allowed = [...Quote::FOLLOW_UP, Quote::EXPIRED];
        if (! in_array($quote->status, $allowed, true)) {
            throw ApiException::rule('QTE-BR-17', 'Solo se rechaza una cotización enviada, vista, en negociación o vencida');
        }

        return DB::transaction(function () use ($quote, $reason, $actor, $portal) {
            $before = $quote->status;
            $version = $quote->currentVersion()->firstOrFail();
            if ($version->status === QuoteVersion::SENT) {
                $version->forceFill(['status' => QuoteVersion::REJECTED])->save();
            }
            $quote->forceFill(['status' => Quote::REJECTED, 'rejected_at' => now(), 'rejected_reason' => $reason])->save();
            $this->audit->log('Cotización', $quote->code, $portal ? 'rechazar_portal' : 'rechazar', Quote::STATUS_LABELS[$before], $reason,
                actor: $portal ? null : $actor, system: $portal && $actor === null);
            if ($quote->owner) {
                $this->notify->push('cotizacion.rechazada', 'portal', $quote->owner, "{$quote->code} rechazada", $reason,
                    ['client_id' => $quote->client_id, 'related_type' => 'quote', 'related_id' => $quote->id]);
            }
            QuoteRejected::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /** JOB-02: borrador/enviada/vista/negociación con validez pasada → vencida (actor Sistema). Idempotente. */
    public function expireDue(): array
    {
        $today = $this->clock->today()->toDateString();
        $expired = [];
        foreach (Quote::whereIn('status', Quote::EXPIRABLE)->whereDate('valid_until', '<', $today)->get() as $quote) {
            DB::transaction(function () use ($quote) {
                $before = $quote->status;
                $quote->forceFill(['status' => Quote::EXPIRED])->save();
                $this->audit->log('Cotización', $quote->code, 'vencer', Quote::STATUS_LABELS[$before], 'Vencida · validez '.$quote->valid_until->toDateString(), system: true);
                QuoteExpired::dispatch($quote->id, $quote->opportunity_id);
            });
            $expired[] = $quote->code;
        }

        return $expired;
    }

    /** Hash del contenido congelado: ítems, montos y condiciones de la versión. */
    public static function contentHash(QuoteVersion $version): string
    {
        $items = $version->items()->get()->map(fn ($i) => [$i->line_no, $i->product_id, $i->segment, $i->service_date->toDateString(), $i->qty, $i->pax, (string) $i->unit_price])->all();

        return hash('sha256', json_encode([
            $version->quote_id, $version->version_no, $items, (string) $version->discount_pct, (string) $version->tax_pct,
            (string) $version->grand_total, $version->conditions, $version->policies_snapshot,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function draftVersion(Quote $quote): QuoteVersion
    {
        $version = $quote->currentVersion()->firstOrFail();
        if ($version->status !== QuoteVersion::DRAFT) {
            throw ApiException::rule('QTE-BR-19', 'La versión vigente ya fue enviada; cree una nueva versión');
        }

        return $version;
    }
}
