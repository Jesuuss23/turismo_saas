<?php

namespace App\Services\Platform;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `billing:renewals` (spec 13 §8.2, diario 06:00): suscripciones cuyo periodo terminó. Aplica el cambio programado (revalida SUB-BR-03),
 * el cupón y el saldo a favor, emite la factura, la cobra, avanza el periodo y procesa `cancel_scheduled → canceled`.
 * Un lock por suscripción evita la doble renovación (equivalente a ShouldBeUnique).
 */
class RenewalService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlatformInvoiceService $invoices,
        private readonly DowngradeValidator $downgrades,
        private readonly PlatformEvents $events,
    ) {}

    /** @return list<array{subscription: string, tenant: string, result: string, invoice: ?string}> */
    public function run(?Carbon $today = null): array
    {
        $today ??= PlatformClock::today();
        $db = self::db();
        $due = $db->table('subscriptions')->whereIn('status', ['active', 'past_due', 'cancel_scheduled'])->where('current_period_end', '<', $today->toDateString())
            ->orderBy('current_period_end')->pluck('id');
        $out = [];
        foreach ($due as $id) {
            $lock = Cache::lock("renewal:{$id}", 120);
            if (! $lock->get()) {
                continue;
            }
            try {
                $sub = $db->table('subscriptions')->where('id', $id)->first();
                if ($sub->current_period_end < $today->toDateString()) {
                    $out[] = $this->renew($sub, $today);
                }
            } finally {
                $lock->release();
            }
        }

        return $out;
    }

    private function renew(object $sub, Carbon $today): array
    {
        $db = self::db();
        $tenant = $db->table('tenants')->where('id', $sub->tenant_id)->first();
        $start = Carbon::parse($sub->current_period_end, PlatformClock::TZ)->addDay();

        if ($sub->status === 'cancel_scheduled' || ! $sub->auto_renew) {
            $monthly = (float) BillingMath::monthly(BillingMath::price($sub), $sub->cycle);
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'canceled', 'auto_renew' => false, 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
            $db->table('subscription_changes')->where('subscription_id', $sub->id)->where('status', 'scheduled')->update(['status' => 'voided']);
            $this->events->audit($sub->tenant_id, 'Suscripción', $sub->code, 'cancelar', 'Cancelación programada', 'Cancelada al cierre del periodo', system: true);
            $this->events->notifyTenant($sub->tenant_id, 'suscripcion.cancelacion', 'Su suscripción terminó',
                'Tiene 30 días de solo lectura para exportar sus datos. Puede volver a contratar cuando quiera.');
            $this->events->lifecycle($sub->tenant_id, 'churn', "{$tenant->trade_name} canceló su suscripción", -$monthly);

            return ['subscription' => $sub->code, 'tenant' => $tenant->code, 'result' => 'canceled', 'invoice' => null];
        }

        // Cambio programado: se revalida SUB-BR-03; si falla, no se aplica y se notifica.
        $planId = $sub->plan_id;
        $cycle = $sub->cycle;
        $change = $db->table('subscription_changes')->where('subscription_id', $sub->id)->where('status', 'scheduled')->where('effective_at', '<=', $start->toDateString())->first();
        $note = null;
        if ($change) {
            $blockers = $change->to_plan_id !== $sub->plan_id ? $this->downgrades->blockers($sub->tenant_id, $change->to_plan_id) : [];
            if ($blockers === []) {
                [$planId, $cycle] = [$change->to_plan_id, $change->to_cycle];
                $db->table('subscription_changes')->where('id', $change->id)->update(['status' => 'applied']);
                $note = 'cambio aplicado';
            } else {
                $db->table('subscription_changes')->where('id', $change->id)->update(['status' => 'voided', 'note' => 'Bloqueado al renovar: '.count($blockers).' bloqueo(s)']);
                $this->events->audit($sub->tenant_id, 'Suscripción', $sub->code, 'downgrade_bloqueado', $change->to_plan_id, implode(' ', array_column($blockers, 'message')), system: true);
                $this->events->notifyTenant($sub->tenant_id, 'suscripcion.cambio_programado', 'No pudimos aplicar su cambio de plan',
                    'El consumo actual supera los límites del plan elegido: '.implode(' ', array_column($blockers, 'message')));
            }
        }

        $before = (float) BillingMath::monthly(BillingMath::price($sub), $sub->cycle);
        $end = BillingMath::periodEnd($start, $cycle);
        $lines = $this->subscriptions->renewalLines($sub, $planId, $cycle, $start, true);
        $db->transaction(function (ConnectionInterface $db) use ($sub, $planId, $cycle, $start, $end) {
            $update = ['plan_id' => $planId, 'cycle' => $cycle, 'current_period_start' => $start->toDateString(), 'current_period_end' => $end->toDateString(),
                'lock_version' => $sub->lock_version + 1, 'updated_at' => now()];
            // PLN-BR-02: vencido el congelamiento de precio, rige el del catálogo.
            if ($sub->price_locked_until !== null && $sub->price_locked_until < $start->toDateString()) {
                $update += ['negotiated_price' => null, 'price_locked_until' => null];
            }
            if ($planId !== $sub->plan_id || $cycle !== $sub->cycle) {
                $update += ['negotiated_price' => null, 'price_locked_until' => null];
            }
            $db->table('subscriptions')->where('id', $sub->id)->update($update);
            if ($planId !== $sub->plan_id) {
                $db->table('tenants')->where('id', $sub->tenant_id)->update(['plan_id' => $planId, 'updated_at' => now()]);
            }
        });
        if ($planId !== $sub->plan_id) {
            EntitlementService::forget($sub->tenant_id);
            $after = (float) BillingMath::monthly(BillingMath::catalogPrice($planId, $cycle), $cycle);
            $this->events->lifecycle($sub->tenant_id, 'downgrade', "{$tenant->trade_name}: {$sub->plan_id} → {$planId}", round($after - $before, 2));
        }
        $fresh = $db->table('subscriptions')->where('id', $sub->id)->first();
        $invoice = $this->invoices->issue($fresh, 'subscription', $lines, $start->toDateString(), $end->toDateString(), 0, $today);
        $charge = $this->invoices->charge($invoice, 'initial', "inv:{$invoice->id}:attempt:1");
        $number = $this->invoices->number($invoice);
        $this->events->audit($sub->tenant_id, 'Suscripción', $sub->code, 'renovacion_automatica', "{$sub->current_period_start} → {$sub->current_period_end}",
            "{$start->toDateString()} → {$end->toDateString()} · {$number}".($note ? " · {$note}" : ''), system: true);

        return ['subscription' => $sub->code, 'tenant' => $tenant->code, 'result' => $charge['attempt']->result === 'succeeded' ? 'paid' : 'failed', 'invoice' => $number];
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
