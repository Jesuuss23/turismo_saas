<?php

namespace App\Http\Resources\Quotes;

use App\Http\Resources\Crm\CrmPresenter;
use App\Models\Iam\User;
use App\Models\Quotes\InternalApproval;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuoteAccess;
use App\Services\Quotes\QuotePolicy;
use App\Services\Quotes\QuotePricing;
use App\Services\Quotes\QuoteWorkflow;
use Carbon\CarbonInterface;

/**
 * Serialización de cotizaciones con omisión de costos y margen según `seeMargin` (§7, §11-16):
 * lo que el rol no ve no se envía. El portal del cliente nunca recibe costos.
 */
class QuotePresenter
{
    public function __construct(
        private readonly QuoteAccess $access,
        private readonly QuotePricing $pricing,
        private readonly QuoteWorkflow $workflow,
        private readonly CrmClock $clock,
        public readonly CrmPresenter $users,
    ) {}

    public function today(): CarbonInterface
    {
        return $this->clock->today();
    }

    /** Fila de lista (pantalla /cotizaciones). */
    public function row(Quote $q, User $user): array
    {
        $v = $q->currentVersion;
        $margin = $this->access->seeMargin($user, $q);

        return [
            'id' => $q->id,
            'code' => $q->code,
            'title' => $q->title,
            'client' => $q->client ? ['id' => $q->client->id, 'code' => $q->client->code, 'name' => $q->client->name] : null,
            'opportunity' => $q->opportunity ? ['id' => $q->opportunity->id, 'code' => $q->opportunity->code] : null,
            'owner' => $this->users->user($q->owner_id),
            ...$this->status($q),
            'version_no' => $q->current_version_no,
            'currency' => $q->currency,
            'total' => (string) $v?->grand_total,
            'total_pen' => $v ? $this->pricing->toPen((string) $v->grand_total, $q->currency, (string) $v->fx_rate) : '0.00',
            'valid_until' => $q->valid_until->toDateString(),
            'days_left' => (int) $this->today()->diffInDays($q->valid_until, false),
            'travel_from' => $q->travel_from->toDateString(),
            'internal' => $v ? QuotePolicy::state($v) : 'no_requerida',
            'created_at' => $q->created_at,
            ...($margin && $v ? ['margin_pct' => (string) $v->margin_pct] : []),
        ];
    }

    /** Detalle 360. */
    public function detail(Quote $q, User $user): array
    {
        $q->loadMissing(['client.primaryContact', 'opportunity', 'currentVersion.items.product', 'currentVersion.internalApproval', 'versions', 'sends', 'views', 'negotiations']);
        $v = $q->currentVersion;
        $margin = $this->access->seeMargin($user, $q);
        $this->users->preloadUsers([$q->owner_id, ...$q->versions->pluck('author_id'), ...$q->sends->pluck('sent_by'), ...$q->negotiations->pluck('created_by')]);
        $token = $user->hasPermissionTo(QuoteAccess::SEND) ? $this->workflow->activeToken($q) : null;

        // array_merge: el detalle reemplaza los resúmenes de la fila (cliente con contacto, oportunidad con etapa).
        return array_merge($this->row($q, $user), [
            'client' => $q->client ? [
                'id' => $q->client->id, 'code' => $q->client->code, 'name' => $q->client->name, 'country' => $q->client->country,
                'segment' => $q->client->segment_code, 'email' => $q->client->email ?? $q->client->primaryContact?->email,
                'phone' => $q->client->phone ?? $q->client->primaryContact?->phone,
            ] : null,
            'opportunity' => $q->opportunity ? ['id' => $q->opportunity->id, 'code' => $q->opportunity->code, 'title' => $q->opportunity->title, 'stage' => $q->opportunity->stage] : null,
            'travel_to' => $q->travel_to->toDateString(),
            'fx_rate' => (string) $q->fx_rate,
            'pax' => ['adt' => $q->pax_adt, 'chd' => $q->pax_chd, 'inf' => $q->pax_inf, 'total' => $q->paxTotal()],
            'viewed_at' => $q->viewed_at,
            'approval' => $q->approved_at ? [
                'at' => $q->approved_at, 'by_name' => $q->approved_by_name, 'channel' => $q->approval_channel, 'ref' => $q->approval_ref,
                'recorded_by' => $this->users->user($q->approval_recorded_by), 'ip' => $q->approval_ip, 'hash' => $q->approval_hash, 'version_no' => $q->approval_version_no,
            ] : null,
            'rejection' => $q->rejected_at ? ['at' => $q->rejected_at, 'reason' => $q->rejected_reason] : null,
            'reservation_id' => $q->reservation_id,
            'duplicated_from' => $q->duplicated_from_id ? Quote::withTrashed()->whereKey($q->duplicated_from_id)->value('code') : null,
            'lock_version' => $q->lock_version,
            'see_margin' => $margin,
            'version' => $v ? $this->version($v, $margin) : null,
            'internal_approval' => $v ? $this->internal($v) : null,
            'token' => $token ? ['url' => QuoteWorkflow::portalUrl($token->plain()), 'created_at' => $token->created_at, 'expires_at' => $token->expires_at->toDateString()] : null,
            'versions' => $q->versions->map(fn (QuoteVersion $x) => $this->versionSummary($x, $margin))->all(),
            'sends' => $q->sends->map(fn ($s) => ['id' => $s->id, 'version_no' => $s->version_no, 'channel' => $s->channel, 'recipient' => $s->recipient,
                'message' => $s->message, 'sent_by' => $this->users->user($s->sent_by), 'sent_at' => $s->sent_at])->all(),
            'views' => $q->views->map(fn ($w) => ['id' => $w->id, 'version_no' => $w->version_no, 'viewed_at' => $w->viewed_at, 'source' => $w->source,
                'ip' => $w->ip, 'recorded_by' => $this->users->user($w->recorded_by)])->all(),
            'negotiations' => $q->negotiations->map(fn ($n) => ['id' => $n->id, 'channel' => $n->channel, 'note' => $n->note,
                'created_version_no' => $n->created_version_no, 'by' => $this->users->user($n->created_by), 'at' => $n->created_at])->all(),
            'actions' => $this->actions($q, $v, $user),
        ]);
    }

    public function versionSummary(QuoteVersion $v, bool $margin): array
    {
        return [
            'id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status, 'status_label' => QuoteVersion::STATUS_LABELS[$v->status],
            'issued_on' => $v->issued_on->toDateString(), 'author' => $this->users->user($v->author_id), 'discount_pct' => (string) $v->discount_pct,
            'grand_total' => (string) $v->grand_total, 'snapshot_label' => $v->snapshot_label, 'frozen_at' => $v->frozen_at,
            ...($margin ? ['margin_pct' => (string) $v->margin_pct] : []),
        ];
    }

    public function version(QuoteVersion $v, bool $margin): array
    {
        $v->loadMissing('items.product');

        return $this->versionSummary($v, $margin) + [
            'tax_pct' => (string) $v->tax_pct,
            'notes' => $v->notes,
            'conditions' => $v->conditions,
            'fx_rate' => (string) $v->fx_rate,
            'content_hash' => $v->content_hash,
            'lock_version' => $v->lock_version,
            'totals' => [
                'sale' => (string) $v->sale_total, 'discount' => (string) $v->discount_total, 'net' => (string) $v->net_total,
                'tax' => (string) $v->tax_total, 'total' => (string) $v->grand_total,
                ...($margin ? ['cost' => (string) $v->cost_total, 'profit' => (string) $v->profit_total, 'margin_pct' => (string) $v->margin_pct, 'markup_pct' => (string) $v->markup_pct] : []),
            ],
            'items' => $v->items->map(fn (QuoteItem $i) => $this->item($i, $margin))->all(),
            'catalog_snapshot' => collect($v->catalog_snapshot ?? [])->map(fn (array $s) => $margin ? $s : array_merge($s, ['rate' => isset($s['rate']) ? array_diff_key($s['rate'], array_flip(['cost', 'suggested_cost'])) : null]))->all(),
            'policies_snapshot' => array_values($v->policies_snapshot ?? []),
        ];
    }

    public function item(QuoteItem $i, bool $margin): array
    {
        return [
            'id' => $i->id, 'line_no' => $i->line_no,
            'product' => $i->product ? ['id' => $i->product->id, 'ref' => $i->product->ref, 'name' => $i->product->name] : null,
            'product_version' => $i->product_version,
            'rate' => $i->rate_id ? ['id' => $i->rate_id, 'ref' => $i->rate_snapshot['ref'] ?? null, 'channel' => $i->rate_snapshot['channel'] ?? null] : null,
            'segment' => $i->segment, 'service_date' => $i->service_date->toDateString(), 'qty' => $i->qty, 'pax' => $i->pax,
            'unit_price' => (string) $i->unit_price, 'sale_total' => (string) $i->sale_total,
            'season_factor' => $i->season_factor !== null ? (string) $i->season_factor : null,
            'season' => $i->rate_snapshot['season']['name'] ?? null,
            'price_source' => $i->price_source,
            ...($margin ? ['unit_cost' => (string) $i->unit_cost, 'cost_total' => (string) $i->cost_total, 'margin_pct' => QuotePricing::lineMargin((string) $i->unit_cost, (string) $i->unit_price)] : []),
        ];
    }

    public function internal(QuoteVersion $v): array
    {
        $pending = $v->approvals()->where('status', InternalApproval::PENDING)->first();
        $last = $v->approvals()->whereIn('status', [InternalApproval::APPROVED, InternalApproval::REJECTED])->first();

        return [
            'state' => QuotePolicy::state($v),
            'reasons' => QuotePolicy::versionReasons($v),
            'over_cap' => QuotePolicy::overCap((string) $v->discount_pct),
            'pending' => $pending ? $this->approval($pending) : null,
            'last_decision' => $last ? $this->approval($last) : null,
        ];
    }

    public function approval(InternalApproval $a): array
    {
        return [
            'id' => $a->id, 'status' => $a->status, 'reasons' => $a->reasons, 'over_cap' => $a->over_cap,
            'requested_by' => $this->users->user($a->requested_by), 'requested_at' => $a->requested_at, 'request_note' => $a->request_note,
            'decided_by' => $this->users->user($a->decided_by), 'decided_at' => $a->decided_at, 'decision_note' => $a->decision_note,
        ];
    }

    /** Estado efectivo y etiqueta (QTE-BR-16: en seguimiento con validez pasada se muestra vencida). */
    public function status(Quote $q): array
    {
        $display = $q->displayStatus($this->today());

        return ['status' => $q->status, 'display_status' => $display, 'status_label' => Quote::STATUS_LABELS[$display], 'expired' => $q->isExpired($this->today())];
    }

    /**
     * Botones del detalle según estado (§7 "Acciones visibles"): sin permiso o con bloqueo se envían
     * deshabilitados con el motivo (principio `pbtn`). `group`: primary | more.
     *
     * @return list<array{key: string, label: string, enabled: bool, reason: ?string, group: string}>
     */
    public function actions(Quote $q, ?QuoteVersion $v, User $user): array
    {
        $out = [];
        $add = function (string $key, string $label, ?string $permission, ?string $blocked = null, string $group = 'primary') use (&$out, $user) {
            $missing = $permission && ! collect(explode('|', $permission))->contains(fn ($p) => $user->hasPermissionTo($p));
            $out[] = ['key' => $key, 'label' => $label, 'enabled' => ! $missing && $blocked === null,
                'reason' => $missing ? 'Requiere permiso '.str_replace('|', ' o ', $permission) : $blocked, 'group' => $group];
        };
        $expired = $q->isExpired($this->today());
        $state = $v ? QuotePolicy::state($v) : 'no_requerida';
        $approver = $user->hasPermissionTo(QuoteAccess::APPROVE_INTERNAL);
        $blocked = $v && QuotePolicy::blocks($v) ? QuotePolicy::blockedMessage($v) : null;

        if ($q->status === Quote::DRAFT) {
            $add('edit', 'Editar ítems', QuoteAccess::CREATE);
            if (in_array($state, ['requerida', 'pendiente', 'rechazada'], true)) {
                if ($approver) {
                    $over = $v && QuotePolicy::overCap((string) $v->discount_pct) && ! $user->hasPermissionTo(QuoteAccess::DISCOUNT_OVERRIDE);
                    $add('approve_internal', 'Aprobar internamente', QuoteAccess::APPROVE_INTERNAL, $over ? 'Descuento > 10 %: requiere quotes.discount_override' : null);
                    $add('reject_internal', 'Rechazar aprobación', QuoteAccess::APPROVE_INTERNAL);
                } else {
                    $add('request_approval', 'Solicitar aprobación a gerencia', QuoteAccess::CREATE.'|'.QuoteAccess::SEND,
                        $state === 'pendiente' ? 'Solicitud pendiente de gerencia' : (! $this->access->canRequestApproval($q, $user) ? 'Solo el asesor dueño solicita la aprobación' : null));
                }
            }
            $add('send', 'Enviar', QuoteAccess::SEND, $blocked);
        } elseif (in_array($q->status, Quote::FOLLOW_UP, true) && ! $expired) {
            $add('negotiate', 'Registrar negociación', QuoteAccess::SEND);
            $add('approve', 'Aprobar (cliente)', QuoteAccess::CLIENT_APPROVAL, $blocked && ! $approver ? $blocked : null);
            $add('send', 'Reenviar', QuoteAccess::SEND);
            $add('reject', 'Rechazar', QuoteAccess::SEND);
        } elseif ($expired) {
            $add('approve', 'Aprobar (cliente)', QuoteAccess::CLIENT_APPROVAL, 'Cotización vencida');
            $add('new_version', 'Crear nueva versión', QuoteAccess::CREATE);
            $add('reject', 'Rechazar', QuoteAccess::SEND);
        } elseif ($q->status === Quote::APPROVED) {
            $add('convert', 'Convertir en reserva', QuoteAccess::CLIENT_APPROVAL, 'Disponible con el módulo de Reservas (spec 04)');
        } elseif ($q->status === Quote::CONVERTED) {
            $add('view_reservation', 'Ver reserva', 'reservations.view', 'Disponible con el módulo de Reservas (spec 04)');
        }

        $add('print', 'Vista de impresión', QuoteAccess::VIEW, null, 'more');
        $add('duplicate', 'Duplicar', QuoteAccess::CREATE, null, 'more');
        if (! $expired) {
            $add('new_version', 'Crear nueva versión', QuoteAccess::CREATE, $q->status === Quote::CONVERTED ? 'Cotización convertida (QTE-BR-18)' : null, 'more');
        }
        $add('compare', 'Comparar versiones', QuoteAccess::VIEW, $q->current_version_no < 2 ? 'Solo hay una versión' : null, 'more');
        $add('token', 'Generar enlace del cliente', QuoteAccess::SEND, null, 'more');
        $add('register_view', 'Registrar apertura del enlace', QuoteAccess::SEND,
            $q->status === Quote::DRAFT ? 'La cotización aún no se envía' : (in_array($q->status, Quote::FOLLOW_UP, true) ? null : 'No aplica en este estado'), 'more');
        $add('reassign', 'Reasignar asesor', QuoteAccess::REASSIGN, null, 'more');

        return $out;
    }

    /** Portal del cliente: sin costos, margen ni datos internos. */
    public function portal(Quote $q): array
    {
        $q->loadMissing(['client', 'currentVersion.items.product']);
        $v = $q->currentVersion;
        $expired = $q->isExpired($this->today());
        $blocked = $v && QuotePolicy::blocks($v);
        $snapshot = collect($v?->catalog_snapshot ?? [])->keyBy('line_no');

        return [
            'code' => $q->code,
            'title' => $q->title,
            ...$this->status($q),
            'client' => $q->client?->name,
            'travel_from' => $q->travel_from->toDateString(),
            'travel_to' => $q->travel_to->toDateString(),
            'valid_until' => $q->valid_until->toDateString(),
            'currency' => $q->currency,
            'pax' => ['adt' => $q->pax_adt, 'chd' => $q->pax_chd, 'inf' => $q->pax_inf],
            'version_no' => $q->current_version_no,
            'items' => $v ? $v->items->map(fn (QuoteItem $i) => [
                'line_no' => $i->line_no, 'product' => $i->product?->name, 'description' => $snapshot[$i->line_no]['product']['short_description'] ?? null,
                'service_date' => $i->service_date->toDateString(), 'segment' => $i->segment, 'qty' => $i->qty, 'pax' => $i->pax,
                'unit_price' => (string) $i->unit_price, 'total' => (string) $i->sale_total,
                'inclusions' => $snapshot[$i->line_no]['inclusions'] ?? [], 'exclusions' => $snapshot[$i->line_no]['exclusions'] ?? [],
            ])->all() : [],
            'totals' => $v ? [
                'sale' => (string) $v->sale_total, 'discount_pct' => (string) $v->discount_pct, 'discount' => (string) $v->discount_total,
                'net' => (string) $v->net_total, 'tax_pct' => (string) $v->tax_pct, 'tax' => (string) $v->tax_total, 'total' => (string) $v->grand_total,
            ] : null,
            'conditions' => $v?->conditions,
            'notes' => $v?->notes,
            'policies' => array_values($v?->policies_snapshot ?? []),
            'approval' => $q->approved_at ? ['at' => $q->approved_at, 'by_name' => $q->approved_by_name, 'version_no' => $q->approval_version_no] : null,
            'rejection' => $q->rejected_at ? ['at' => $q->rejected_at, 'reason' => $q->rejected_reason] : null,
            'can_respond' => in_array($q->status, Quote::FOLLOW_UP, true) && ! $expired && ! $blocked,
            'notice' => match (true) {
                $expired => 'Esta cotización venció. Pida a su asesor una versión actualizada.',
                $blocked && in_array($q->status, Quote::FOLLOW_UP, true) => 'Su asesor está revisando la cotización; podrá aprobarla en breve.',
                default => null,
            },
        ];
    }
}
