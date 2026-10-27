<?php

namespace App\Services\Platform;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Services\Platform\Gateway\PaymentGateway;
use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suscripción del tenant (spec 13 §2 `admin/suscripcion`, §4.1, §5): plan y ciclo, cambio con prorrateo (SUB-BR-01/02/03), add-ons
 * (SUB-BR-06), cupón (SUB-BR-07), cancelación con encuesta (SUB-BR-11), datos de facturación (SUB-BR-09), facturas y tarjeta.
 * Las tablas son de plataforma: todo se filtra por el tenant autenticado.
 */
class SubscriptionService
{
    public function __construct(
        private readonly PlatformInvoiceService $invoices,
        private readonly PlatformEvents $events,
        private readonly LimitService $limits,
        private readonly EntitlementService $entitlements,
        private readonly DowngradeValidator $downgrades,
        private readonly CouponService $coupons,
        private readonly PaymentGateway $gateway,
    ) {}

    public function current(string $tenantId): object
    {
        return self::db()->table('subscriptions')->where('tenant_id', $tenantId)->where('status', '<>', 'canceled')->first()
            ?? self::db()->table('subscriptions')->where('tenant_id', $tenantId)->orderByDesc('created_at')->first()
            ?? throw ApiException::notFound();
    }

    // ───────────────────────────── Lectura ─────────────────────────────

    public function show(string $tenantId): array
    {
        $db = self::db();
        $sub = $this->current($tenantId);
        $plans = $db->table('plans')->orderBy('rank')->get()->keyBy('id');
        $plan = $plans[$sub->plan_id];
        $today = PlatformClock::today();
        $method = $db->table('payment_methods')->where('tenant_id', $tenantId)->where('is_default', true)->where('status', '<>', 'removed')->first();
        $change = $db->table('subscription_changes')->where('subscription_id', $sub->id)->where('status', 'scheduled')->first();
        $coupon = $this->coupons->active($sub);
        $debt = $this->invoices->debt($tenantId);
        $trialLeft = $sub->status === 'trialing' && $sub->trial_ends_at ? max(0, (int) $today->diffInDays(Carbon::parse($sub->trial_ends_at, PlatformClock::TZ), false)) : null;

        $banners = [];
        if ($sub->status === 'trialing') {
            $banners[] = ['level' => 'info', 'text' => "Está en periodo de prueba: le quedan {$trialLeft} día(s). Registre su tarjeta para continuar sin interrupciones."];
        }
        if ($sub->status === 'past_due') {
            $banners[] = ['level' => 'warn', 'text' => 'Tiene un pago pendiente de '.BillingMath::money($debt).'. Actualice su tarjeta o pague desde Facturas para evitar la suspensión.'];
        }
        if ($sub->status === 'suspended') {
            $banners[] = ['level' => 'error', 'text' => 'Su cuenta está suspendida por falta de pago. Pague la deuda de '.BillingMath::money($debt).' para reactivarla.'];
        }
        if ($sub->status === 'cancel_scheduled') {
            $banners[] = ['level' => 'warn', 'text' => 'Cancelación programada: su suscripción termina el '.Carbon::parse($sub->cancel_at)->format('d/m/Y').'. Puede reanudarla antes de esa fecha.'];
        }
        if ($change) {
            $banners[] = ['level' => 'info', 'text' => 'Cambio programado a '.$plans[$change->to_plan_id]->name.' '.mb_strtolower(PlatformCatalog::CYCLES[$change->to_cycle])
                .' desde el '.Carbon::parse($change->effective_at)->format('d/m/Y').'.'];
        }
        foreach ($this->limits->usage($tenantId) as $m) {
            if ($m['level'] !== 'ok' && $m['limit'] !== 0) {
                $banners[] = ['level' => $m['level'] === 'limit' ? 'error' : 'warn', 'text' => "{$m['label']}: lleva el {$m['pct']} % del límite de su plan."];
            }
        }

        return [
            'subscription' => [
                'id' => $sub->id, 'code' => $sub->code, 'plan_id' => $sub->plan_id, 'plan_name' => $plan->name, 'cycle' => $sub->cycle, 'cycle_label' => PlatformCatalog::CYCLES[$sub->cycle],
                'status' => $sub->status, 'status_label' => PlatformCatalog::SUBSCRIPTION_STATUS[$sub->status], 'period_start' => $sub->current_period_start,
                'period_end' => $sub->current_period_end, 'renews_on' => Carbon::parse($sub->current_period_end)->addDay()->toDateString(), 'auto_renew' => (bool) $sub->auto_renew,
                'cancel_at' => $sub->cancel_at, 'trial_ends_at' => $sub->trial_ends_at, 'trial_days_left' => $trialLeft, 'trial_extended_days' => (int) $sub->trial_extended_days,
                'credit_balance' => (float) $sub->credit_balance, 'price' => (float) BillingMath::price($sub), 'negotiated' => $sub->negotiated_price !== null,
                'lock_version' => (int) $sub->lock_version,
                'billing' => ['ruc' => $sub->billing_ruc, 'name' => $sub->billing_name, 'address' => $sub->billing_address, 'ubigeo' => $sub->billing_ubigeo, 'email' => $sub->billing_email],
            ],
            'next_charge' => $this->nextCharge($sub),
            'payment_method' => $method ? ['brand' => $method->brand, 'last4' => $method->last4, 'exp' => sprintf('%02d/%d', $method->exp_month, $method->exp_year),
                'holder' => $method->holder, 'status' => $method->status] : null,
            'scheduled_change' => $change ? ['id' => $change->id, 'kind' => $change->kind, 'to_plan_id' => $change->to_plan_id, 'to_plan_name' => $plans[$change->to_plan_id]->name,
                'to_cycle' => $change->to_cycle, 'effective_at' => $change->effective_at] : null,
            'coupon' => $coupon ? ['code' => $coupon->code, 'label' => CouponService::describe($coupon), 'remaining_cycles' => (int) $coupon->remaining_cycles] : null,
            'addons' => $this->addons($sub),
            'modules' => array_values(array_filter($this->entitlements->for($tenantId), fn ($f) => $f['enabled'])),
            'plans' => $this->comparePlans($sub),
            'banners' => $banners,
            'debt' => $debt,
            'cancel_reasons' => PlatformCatalog::CANCEL_REASONS,
        ];
    }

    /** Próximo cargo estimado de la renovación (plan + add-ons vigentes − cupón − saldo a favor, + IGV). */
    public function nextCharge(object $sub): array
    {
        $date = Carbon::parse($sub->current_period_end)->addDay();
        $change = self::db()->table('subscription_changes')->where('subscription_id', $sub->id)->where('status', 'scheduled')->first();
        $lines = $this->renewalLines($sub, $change?->to_plan_id ?? $sub->plan_id, $change?->to_cycle ?? $sub->cycle, $date, false);
        $subtotal = array_reduce($lines, fn ($c, $l) => $c->plus(BigDecimal::of($l[2])->multipliedBy((string) $l[1])), BigDecimal::zero()->toScale(2));
        $subtotal = BigDecimal::max($subtotal, BigDecimal::zero()->toScale(2));
        $igv = BillingMath::igv((string) $subtotal);

        return ['date' => $date->toDateString(), 'subtotal' => (float) (string) $subtotal, 'igv' => (float) $igv, 'total' => (float) (string) $subtotal->plus($igv),
            'lines' => array_map(fn ($l) => ['description' => $l[0], 'quantity' => (float) $l[1], 'unit_price' => (float) $l[2], 'type' => $l[3]], $lines),
            'skipped' => $sub->status === 'cancel_scheduled' || ! $sub->auto_renew];
    }

    /**
     * Líneas de la factura de renovación (§8.2): plan del nuevo ciclo, add-ons vigentes en el nuevo periodo, cupón y saldo a favor.
     * Con `$consume` el cupón gasta un ciclo y el saldo a favor se descuenta.
     *
     * @return list<array{0: string, 1: int|float, 2: string, 3: string}>
     */
    public function renewalLines(object $sub, string $planId, string $cycle, Carbon $periodStart, bool $consume): array
    {
        $db = self::db();
        $planName = $db->table('plans')->where('id', $planId)->value('name');
        $end = BillingMath::periodEnd($periodStart, $cycle);
        $price = BillingMath::price($sub, $planId, $cycle, $periodStart);
        $lines = [["Plan {$planName} ".mb_strtolower(PlatformCatalog::CYCLES[$cycle]).' · '.$periodStart->format('d/m/Y').' al '.$end->format('d/m/Y'), 1, $price, 'plan']];
        $base = BigDecimal::of($price);
        $items = $db->table('subscription_items as i')->join('addons as a', 'a.code', '=', 'i.addon_code')->where('i.subscription_id', $sub->id)
            ->where(fn ($q) => $q->whereNull('i.ends_at')->orWhere('i.ends_at', '>=', $periodStart->toDateString()))->get(['i.*', 'a.label', 'a.available_plans', 'a.included_plans']);
        foreach ($items as $item) {
            if (in_array($planId, json_decode($item->included_plans, true), true) || ! in_array($planId, json_decode($item->available_plans, true), true)) {
                continue;
            }
            $unit = BigDecimal::of((string) $item->unit_price)->multipliedBy($cycle === 'annual' ? 12 : 1)->toScale(2);
            $lines[] = ["Add-on {$item->label}", (int) $item->quantity, (string) $unit, 'addon'];
            $base = $base->plus($unit->multipliedBy((int) $item->quantity));
        }
        if ($coupon = $this->coupons->active($sub)) {
            $discount = CouponService::discount($coupon, (string) $base);
            $lines[] = ["Cupón {$coupon->code} (".CouponService::describe($coupon).')', 1, (string) BigDecimal::of($discount)->negated(), 'discount'];
            $base = $base->minus($discount);
            if ($consume) {
                $this->coupons->consume($coupon);
            }
        }
        $credit = BigDecimal::min(BigDecimal::of((string) $sub->credit_balance), BigDecimal::max($base, BigDecimal::zero()));
        if ($credit->isPositive()) {
            $lines[] = ['Saldo a favor por prorrateo', 1, (string) $credit->negated()->toScale(2), 'proration_credit'];
            if ($consume) {
                $db->table('subscriptions')->where('id', $sub->id)->update(['credit_balance' => (string) BigDecimal::of((string) $sub->credit_balance)->minus($credit)]);
            }
        }

        return $lines;
    }

    /** Add-ons del catálogo con su estado para el tenant (contratado, retiro programado, incluido, disponible, no disponible). */
    public function addons(object $sub): array
    {
        $db = self::db();
        $items = $db->table('subscription_items')->where('subscription_id', $sub->id)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', PlatformClock::today()->toDateString()))->get()->keyBy('addon_code');

        return $db->table('addons')->orderBy('sort')->get()->map(function ($a) use ($sub, $items) {
            $item = $items[$a->code] ?? null;
            $included = in_array($sub->plan_id, json_decode($a->included_plans, true), true);
            $available = in_array($sub->plan_id, json_decode($a->available_plans, true), true);
            $state = match (true) {
                $included => 'included',
                $item !== null && $item->ends_at !== null => 'ending',
                $item !== null => 'active',
                $available => 'available',
                default => 'unavailable',
            };

            return ['code' => $a->code, 'label' => $a->label, 'unit' => $a->unit, 'quantity_allowed' => (bool) $a->quantity_allowed,
                'price' => (float) BillingMath::addonPrice($a->code, $sub->plan_id), 'grants' => json_decode($a->grants, true), 'state' => $state,
                'quantity' => $item ? (int) $item->quantity : 0, 'ends_at' => $item?->ends_at];
        })->all();
    }

    /** Comparativo de planes (v2.2): precio, límites, nº de reglas de reserva y lo que suma cada plan. */
    public function comparePlans(object $sub): array
    {
        $db = self::db();
        $plans = $db->table('plans')->where('status', '<>', 'retired')->orderBy('rank')->get();
        $ranks = $db->table('plans')->pluck('rank', 'id');
        $rules = $db->table('plan_rule_entitlements as e')->join('booking_rule_catalog as c', 'c.code', '=', 'e.rule_code')->orderBy('c.sort')->get(['c.code', 'c.label', 'e.min_plan']);
        $limits = $db->table('plan_limits')->get()->groupBy('plan_id');

        return $plans->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'rank' => (int) $p->rank, 'price_month' => (float) $p->price_monthly, 'price_year' => (float) $p->price_year,
            'trial_days' => (int) $p->trial_days, 'current' => $p->id === $sub->plan_id,
            'limits' => ($limits[$p->id] ?? collect())->mapWithKeys(fn ($l) => [$l->metric => $l->value === null ? null : (int) $l->value])->all(),
            'rules_count' => $rules->filter(fn ($r) => $ranks[$r->min_plan] <= $p->rank)->count(),
            'rules_added' => $rules->filter(fn ($r) => $r->min_plan === $p->id)->pluck('label')->values()->all(),
            'direction' => $p->rank > $ranks[$sub->plan_id] ? 'upgrade' : ($p->rank < $ranks[$sub->plan_id] ? 'downgrade' : 'current'),
        ])->all();
    }

    /** v2.2 «Qué incluye su plan»: reglas incluidas por grupo, bloqueadas con su plan mínimo y lo que suma cada upgrade. */
    public function planRules(string $tenantId): array
    {
        $db = self::db();
        $sub = $this->current($tenantId);
        $ranks = $db->table('plans')->pluck('rank', 'id');
        $names = $db->table('plans')->pluck('name', 'id');
        $rules = $db->table('plan_rule_entitlements as e')->join('booking_rule_catalog as c', 'c.code', '=', 'e.rule_code')->orderBy('c.sort')
            ->get(['c.code', 'c.label', 'c.group_key', 'e.min_plan', 'e.is_demo']);
        $groups = [];
        foreach (PlatformCatalog::RULE_GROUPS as $key => $label) {
            $groups[$key] = ['key' => $key, 'label' => $label, 'rules' => []];
        }
        foreach ($rules as $r) {
            $groups[$r->group_key]['rules'][] = ['code' => $r->code, 'label' => $r->label, 'min_plan' => $r->min_plan, 'min_plan_name' => $names[$r->min_plan],
                'included' => $ranks[$r->min_plan] <= $ranks[$sub->plan_id]];
        }
        $upgrades = [];
        foreach ($db->table('plans')->where('rank', '>', $ranks[$sub->plan_id])->orderBy('rank')->get() as $p) {
            $upgrades[] = ['plan_id' => $p->id, 'plan_name' => $p->name,
                'adds' => $rules->filter(fn ($r) => $ranks[$r->min_plan] > $ranks[$sub->plan_id] && $ranks[$r->min_plan] <= $p->rank)->pluck('label')->values()->all()];
        }

        return ['plan_id' => $sub->plan_id, 'plan_name' => $names[$sub->plan_id], 'is_demo' => (bool) $rules->contains('is_demo', true),
            'included' => $rules->filter(fn ($r) => $ranks[$r->min_plan] <= $ranks[$sub->plan_id])->count(), 'total' => $rules->count(),
            'groups' => array_values($groups), 'upgrades' => $upgrades];
    }

    /** Historial de consumo mensual (6 meses). */
    public function history(string $tenantId): array
    {
        $db = self::db();
        $months = [];
        $today = PlatformClock::today();
        for ($i = 5; $i >= 0; $i--) {
            $months[] = $today->copy()->startOfMonth()->subMonthsNoOverflow($i);
        }
        $rows = $db->table('usage_monthly')->where('tenant_id', $tenantId)->where('month', '>=', $months[0]->toDateString())->get()
            ->groupBy(fn ($r) => substr($r->month, 0, 7));
        $current = collect($this->limits->usage($tenantId))->keyBy('metric');
        $out = [];
        foreach ($months as $m) {
            $key = $m->format('Y-m');
            $row = ['month' => $key];
            foreach (PlatformCatalog::HISTORY as $metric) {
                $row[$metric] = $key === $today->format('Y-m')
                    ? (float) ($current[$metric]['used'] ?? 0)
                    : (float) (($rows[$key] ?? collect())->firstWhere('metric', $metric)?->value ?? 0);
            }
            $out[] = $row;
        }

        return ['metrics' => array_map(fn ($m) => ['key' => $m, 'label' => PlatformCatalog::METRICS[$m][0], 'unit' => PlatformCatalog::METRICS[$m][1]], PlatformCatalog::HISTORY),
            'months' => $out];
    }

    // ───────────────────────────── Cambio de plan (SUB-BR-01/02/03) ─────────────────────────────

    /** @return array{kind: string, immediate: bool, proration: ?array, blockers: list<array>, effective_at: string, rules_gained: list<string>, rules_lost: list<string>} */
    public function previewChange(string $tenantId, string $planId, string $cycle): array
    {
        $sub = $this->current($tenantId);
        [$kind, $immediate] = $this->classify($sub, $planId, $cycle);
        $plan = self::db()->table('plans')->where('id', $planId)->first();
        $today = PlatformClock::today();
        $proration = null;
        if ($immediate && $sub->status !== 'trialing') {
            $proration = ProrationCalculator::compute(BillingMath::price($sub), $sub->cycle, (string) $plan->price_monthly, (string) $plan->price_year, $cycle,
                Carbon::parse($sub->current_period_start, PlatformClock::TZ), Carbon::parse($sub->current_period_end, PlatformClock::TZ), $today,
                PlatformInvoiceService::issuer()['igv_pct']);
        }
        [$gained, $lost] = $this->ruleDiff($sub->plan_id, $planId);

        return ['kind' => $kind, 'immediate' => $immediate, 'proration' => $proration,
            'blockers' => $kind === 'downgrade' ? $this->downgrades->blockers($tenantId, $planId) : [],
            'effective_at' => $immediate ? $today->toDateString() : Carbon::parse($sub->current_period_end)->addDay()->toDateString(),
            'plan' => ['id' => $plan->id, 'name' => $plan->name], 'cycle' => $cycle, 'price' => (float) ($cycle === 'annual' ? $plan->price_year : $plan->price_monthly),
            'rules_gained' => $gained, 'rules_lost' => $lost];
    }

    public function change(string $tenantId, string $planId, string $cycle, ?string $ifMatch, User $actor): array
    {
        $sub = $this->current($tenantId);
        $this->assertVersion($sub, $ifMatch);
        if (in_array($sub->status, ['suspended', 'cancel_scheduled', 'canceled'], true)) {
            throw ApiException::conflict('SUBSCRIPTION_NOT_CHANGEABLE', $sub->status === 'cancel_scheduled'
                ? 'Reanude la suscripción antes de cambiar de plan.' : 'Regularice el pago pendiente antes de cambiar de plan.');
        }
        $preview = $this->previewChange($tenantId, $planId, $cycle);
        $db = self::db();
        $plans = $db->table('plans')->pluck('name', 'id');
        $fromLabel = $plans[$sub->plan_id].' '.mb_strtolower(PlatformCatalog::CYCLES[$sub->cycle]);
        $toLabel = $plans[$planId].' '.mb_strtolower(PlatformCatalog::CYCLES[$cycle]);

        if (! $preview['immediate']) {
            if ($preview['blockers'] !== []) {
                $this->events->audit($tenantId, 'Suscripción', $sub->code, 'downgrade_bloqueado', $fromLabel, $toLabel.' · '.count($preview['blockers']).' bloqueo(s)', $actor);

                throw new ApiException(422, 'SUB_DOWNGRADE_BLOCKED', 'SUB-BR-03', "No puede pasar a {$plans[$planId]}: reduzca el consumo o desactive los módulos indicados.",
                    [], ['blockers' => $preview['blockers']]);
            }
            if ($db->table('subscription_changes')->where('subscription_id', $sub->id)->where('status', 'scheduled')->exists()) {
                throw ApiException::conflict('SUB_CHANGE_ALREADY_SCHEDULED', 'Ya hay un cambio programado. Anúlelo antes de programar otro.');
            }
            $db->table('subscription_changes')->insert($this->changeRow($sub, $preview['kind'], $planId, $cycle, $preview['effective_at'], 'scheduled', null, null, $actor));
            $this->bump($sub);
            $this->events->audit($tenantId, 'Suscripción', $sub->code, 'cambio_plan_programado', $fromLabel, "{$toLabel} desde ".Carbon::parse($preview['effective_at'])->format('d/m/Y'), $actor);
            $this->events->notifyTenant($tenantId, 'suscripcion.cambio_programado', "Cambio de plan programado a {$toLabel}",
                'El cambio se aplicará el '.Carbon::parse($preview['effective_at'])->format('d/m/Y').', al cierre de su periodo actual. No hay reembolso por el periodo en curso.');

            return ['message' => "Cambio a {$toLabel} programado para el ".Carbon::parse($preview['effective_at'])->format('d/m/Y').'.', 'preview' => $preview] + $this->show($tenantId);
        }

        // Upgrade inmediato: factura de prorrateo cobrada al instante; un neto negativo va a credit_balance.
        $invoice = null;
        $p = $preview['proration'];
        $update = ['plan_id' => $planId, 'cycle' => $cycle, 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()];
        if ($p && $p['new_period']) {
            $update += ['current_period_start' => $p['period_start'], 'current_period_end' => $p['period_end'], 'billing_anchor_day' => min(28, PlatformClock::today()->day)];
        }
        if ($p && BigDecimal::of($p['net'])->isNegative()) {
            $update['credit_balance'] = (string) BigDecimal::of((string) $sub->credit_balance)->plus(BigDecimal::of($p['net'])->negated());
        }
        $before = (float) BillingMath::monthly(BillingMath::price($sub), $sub->cycle);
        $db->transaction(function (ConnectionInterface $db) use ($sub, $update, $planId) {
            $db->table('subscriptions')->where('id', $sub->id)->update($update);
            $db->table('tenants')->where('id', $sub->tenant_id)->update(['plan_id' => $planId, 'updated_at' => now()]);
        });
        $fresh = $this->current($tenantId);
        if ($p && BigDecimal::of($p['net'])->isPositive()) {
            $note = $p['new_period'] ? 'nuevo periodo de 12 meses' : "prorrateo {$p['days_left']}/{$p['period_days']} días";
            $invoice = $this->invoices->issue($fresh, 'proration', [
                ["Cambio a {$toLabel} ({$note})", 1, $p['charge'], 'proration_charge'],
                ["Crédito no usado de {$fromLabel} ({$p['days_left']}/{$p['period_days']} días)", 1, (string) BigDecimal::of($p['credit'])->negated(), 'proration_credit'],
            ], $p['period_start'], $p['period_end']);
        }
        $db->table('subscription_changes')->insert($this->changeRow($sub, $preview['kind'], $planId, $cycle, PlatformClock::today()->toDateString(), 'applied', $p, $invoice?->id, $actor));
        EntitlementService::forget($tenantId);
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'cambio_plan', $fromLabel, $toLabel.($invoice ? ' · '.$this->invoices->number($invoice) : ''), $actor);
        $this->events->notifyTenant($tenantId, 'suscripcion.plan_cambiado', "Su plan cambió a {$toLabel}",
            $invoice ? "Emitimos la factura {$this->invoices->number($invoice)} por S/ {$invoice->total} (prorrateo)." : 'El cambio ya está vigente.');
        $after = (float) BillingMath::monthly(BillingMath::price($fresh), $cycle);
        $this->events->lifecycle($tenantId, 'upgrade', "{$this->tenantName($tenantId)}: {$fromLabel} → {$toLabel}", $sub->status === 'trialing' ? 0 : round($after - $before, 2));
        $charge = $invoice ? $this->invoices->charge($invoice, 'initial', "inv:{$invoice->id}:attempt:1", $actor) : null;

        return ['message' => "Plan cambiado a {$toLabel}.".($charge && $charge['attempt']->result !== 'succeeded' ? ' El cobro fue rechazado: revise su tarjeta.' : ''),
            'invoice' => $invoice ? $this->invoiceRow($this->invoices->find($invoice->id)) : null, 'preview' => $preview] + $this->show($tenantId);
    }

    public function cancelScheduledChange(string $tenantId, ?string $ifMatch, User $actor): array
    {
        $sub = $this->current($tenantId);
        $this->assertVersion($sub, $ifMatch);
        $change = self::db()->table('subscription_changes')->where('subscription_id', $sub->id)->where('status', 'scheduled')->first()
            ?? throw ApiException::conflict('NO_SCHEDULED_CHANGE', 'No hay un cambio programado.');
        self::db()->table('subscription_changes')->where('id', $change->id)->update(['status' => 'voided']);
        $this->bump($sub);
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'anular_cambio_programado', "{$change->to_plan_id} {$change->to_cycle}", 'Sin cambio programado', $actor);

        return ['message' => 'Cambio programado anulado.'] + $this->show($tenantId);
    }

    /** upgrade (rank mayor), cycle (mismo plan, cambio de ciclo) o downgrade; inmediato salvo downgrade y anual → mensual. */
    private function classify(object $sub, string $planId, string $cycle): array
    {
        $db = self::db();
        $target = $db->table('plans')->where('id', $planId)->first();
        if (! $target || $target->status === 'retired') {
            throw ApiException::invalid('plan', 'Plan inexistente.');
        }
        if (! isset(PlatformCatalog::CYCLES[$cycle])) {
            throw ApiException::invalid('cycle', 'Ciclo inválido.');
        }
        $rank = (int) $db->table('plans')->where('id', $sub->plan_id)->value('rank');
        if ($planId === $sub->plan_id && $cycle === $sub->cycle) {
            throw ApiException::unprocessable('SUB-BR-01', 'Ya está en ese plan y ciclo.');
        }
        if ($sub->status === 'trialing') {
            return [(int) $target->rank >= $rank ? ($planId === $sub->plan_id ? 'cycle' : 'upgrade') : 'downgrade', true];
        }
        if ((int) $target->rank > $rank) {
            if ($sub->cycle === 'annual' && $cycle === 'monthly') {
                throw ApiException::unprocessable('SUB-BR-01', 'Mejore el plan manteniendo el ciclo anual; el paso a mensual se programa aparte.');
            }

            return ['upgrade', true];
        }
        if ((int) $target->rank === $rank) {
            return ['cycle', $cycle === 'annual'];
        }

        return ['downgrade', false];
    }

    private function ruleDiff(string $from, string $to): array
    {
        $db = self::db();
        $ranks = $db->table('plans')->pluck('rank', 'id');
        $rules = $db->table('plan_rule_entitlements as e')->join('booking_rule_catalog as c', 'c.code', '=', 'e.rule_code')->orderBy('c.sort')->get(['c.label', 'e.min_plan']);
        $in = fn ($plan) => $rules->filter(fn ($r) => $ranks[$r->min_plan] <= $ranks[$plan])->pluck('label');

        return [$in($to)->diff($in($from))->values()->all(), $in($from)->diff($in($to))->values()->all()];
    }

    private function changeRow(object $sub, string $kind, ?string $planId, ?string $cycle, string $effective, string $status, ?array $proration, ?string $invoiceId, User $actor): array
    {
        return ['id' => (string) Str::uuid7(), 'subscription_id' => $sub->id, 'kind' => $kind, 'from_plan_id' => $sub->plan_id, 'to_plan_id' => $planId ?? $sub->plan_id,
            'from_cycle' => $sub->cycle, 'to_cycle' => $cycle ?? $sub->cycle, 'effective_at' => $effective, 'status' => $status,
            'proration' => $proration ? json_encode(array_intersect_key($proration, array_flip(['days_left', 'period_days', 'charge', 'credit', 'net']))) : null,
            'invoice_id' => $invoiceId, 'requested_by' => $actor->id, 'requested_by_name' => $actor->name, 'created_at' => now()];
    }

    // ───────────────────────────── Cancelación (SUB-BR-11) ─────────────────────────────

    public function cancel(string $tenantId, array $survey, ?string $ifMatch, User $actor): array
    {
        $sub = $this->current($tenantId);
        $this->assertVersion($sub, $ifMatch);
        if (! in_array($sub->status, ['active', 'past_due', 'trialing'], true)) {
            throw ApiException::conflict('SUBSCRIPTION_NOT_CANCELABLE', 'La suscripción no se puede cancelar en su estado actual.');
        }
        if (! isset(PlatformCatalog::CANCEL_REASONS[$survey['reason'] ?? ''])) {
            throw ApiException::invalid('reason', 'SUB-BR-11: indique el motivo de la cancelación.');
        }
        if (empty($survey['accept'])) {
            throw ApiException::invalid('accept', 'SUB-BR-11: acepte las condiciones de cierre.');
        }
        $cancelAt = $sub->status === 'trialing' ? $sub->trial_ends_at : $sub->current_period_end;
        $payload = ['reason' => $survey['reason'], 'to' => $survey['to'] ?? null, 'score' => isset($survey['score']) ? (int) $survey['score'] : null,
            'stay' => $survey['stay'] ?? null, 'comment' => isset($survey['comment']) ? Str::limit((string) $survey['comment'], 500) : null,
            'export_requested' => (bool) ($survey['export_requested'] ?? false)];
        self::db()->table('subscriptions')->where('id', $sub->id)->update(['status' => 'cancel_scheduled', 'auto_renew' => false, 'cancel_at' => $cancelAt,
            'cancel_survey' => json_encode($payload), 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
        self::db()->table('subscription_changes')->where('subscription_id', $sub->id)->where('status', 'scheduled')->update(['status' => 'voided']);
        $date = Carbon::parse($cancelAt)->format('d/m/Y');
        $reason = PlatformCatalog::CANCEL_REASONS[$survey['reason']];
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'cancelar', PlatformCatalog::SUBSCRIPTION_STATUS[$sub->status], "Cancelación programada al {$date} · {$reason}", $actor);
        $this->events->notifyTenant($tenantId, 'suscripcion.cancelacion', 'Cancelación programada',
            "Su suscripción seguirá activa hasta el {$date}. Luego tendrá 30 días de solo lectura para exportar sus datos. Puede reanudarla antes de esa fecha.");
        $this->events->notifyOps($tenantId, 'suscripcion.cancelacion', "Cancelación de {$this->tenantName($tenantId)}", "Motivo: {$reason}. Efectiva al {$date}.");

        return ['message' => "Cancelación programada para el {$date}."] + $this->show($tenantId);
    }

    public function resume(string $tenantId, ?string $ifMatch, User $actor): array
    {
        $sub = $this->current($tenantId);
        $this->assertVersion($sub, $ifMatch);
        if ($sub->status !== 'cancel_scheduled') {
            throw ApiException::conflict('SUBSCRIPTION_NOT_CANCEL_SCHEDULED', 'La suscripción no tiene una cancelación programada.');
        }
        $status = $sub->trial_ends_at && $sub->trial_ends_at >= PlatformClock::today()->toDateString() && $sub->current_period_end === $sub->trial_ends_at
            ? 'trialing' : ($this->invoices->debt($tenantId) > 0 ? 'past_due' : 'active');
        self::db()->table('subscriptions')->where('id', $sub->id)->update(['status' => $status, 'auto_renew' => true, 'cancel_at' => null,
            'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'reanudar', 'Cancelación programada', PlatformCatalog::SUBSCRIPTION_STATUS[$status], $actor);
        $this->events->notifyTenant($tenantId, 'suscripcion.reanudada', 'Suscripción reanudada', 'Su suscripción continúa con renovación automática.');

        return ['message' => 'Suscripción reanudada.'] + $this->show($tenantId);
    }

    /** PATCH: renovación automática y datos de facturación (SUB-BR-09: se copian al emitir; las emitidas no cambian). */
    public function update(string $tenantId, array $data, ?string $ifMatch, User $actor): array
    {
        $sub = $this->current($tenantId);
        $this->assertVersion($sub, $ifMatch);
        $update = [];
        $changes = [];
        if (array_key_exists('auto_renew', $data)) {
            $update['auto_renew'] = (bool) $data['auto_renew'];
            $changes[] = 'Renovación automática '.($update['auto_renew'] ? 'activada' : 'desactivada');
        }
        $billing = (array) ($data['billing'] ?? []);
        if (isset($billing['ruc']) && ! preg_match('/^(10|15|17|20)\d{9}$/', (string) $billing['ruc'])) {
            throw ApiException::invalid('billing.ruc', 'SUB-BR-09: el RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.');
        }
        if (isset($billing['email']) && ! filter_var($billing['email'], FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid('billing.email', 'Correo de facturación inválido.');
        }
        if (isset($billing['ubigeo']) && $billing['ubigeo'] !== '' && ! preg_match('/^\d{6}$/', (string) $billing['ubigeo'])) {
            throw ApiException::invalid('billing.ubigeo', 'El ubigeo tiene 6 dígitos.');
        }
        foreach (['ruc', 'name', 'address', 'ubigeo', 'email'] as $field) {
            if (array_key_exists($field, $billing)) {
                $value = trim((string) $billing[$field]);
                if ($value === '' && $field !== 'ubigeo') {
                    throw ApiException::invalid("billing.{$field}", 'Este dato de facturación es obligatorio.');
                }
                $update["billing_{$field}"] = $value === '' ? null : Str::limit($value, $field === 'email' ? 150 : 200, '');
            }
        }
        if ($update === []) {
            throw ApiException::invalid('billing', 'No hay cambios.');
        }
        self::db()->table('subscriptions')->where('id', $sub->id)->update($update + ['lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
        if (array_intersect_key($update, array_flip(['billing_ruc', 'billing_name', 'billing_address', 'billing_ubigeo', 'billing_email']))) {
            $changes[] = 'Datos de facturación: RUC '.($update['billing_ruc'] ?? $sub->billing_ruc);
            $this->events->audit($tenantId, 'Suscripción', $sub->code, 'datos_facturacion', "RUC {$sub->billing_ruc}", 'RUC '.($update['billing_ruc'] ?? $sub->billing_ruc), $actor);
        }
        if (isset($update['auto_renew'])) {
            $this->events->audit($tenantId, 'Suscripción', $sub->code, 'renovacion_automatica', $sub->auto_renew ? 'Sí' : 'No', $update['auto_renew'] ? 'Sí' : 'No', $actor);
        }

        return ['message' => implode('. ', $changes).'.'] + $this->show($tenantId);
    }

    // ───────────────────────────── Add-ons (SUB-BR-06) ─────────────────────────────

    public function addAddon(string $tenantId, string $code, int $quantity, User $actor): array
    {
        $db = self::db();
        $sub = $this->current($tenantId);
        $addon = $db->table('addons')->where('code', $code)->first() ?? throw ApiException::notFound();
        $this->assertOperable($sub);
        if (in_array($sub->plan_id, json_decode($addon->included_plans, true), true)) {
            throw ApiException::conflict('ADDON_INCLUDED', "{$addon->label} ya viene incluido en su plan.");
        }
        if (! in_array($sub->plan_id, json_decode($addon->available_plans, true), true)) {
            throw ApiException::unprocessable('ADDON_NOT_AVAILABLE', "{$addon->label} no está disponible en su plan.");
        }
        $today = PlatformClock::today()->toDateString();
        $item = $db->table('subscription_items')->where('subscription_id', $sub->id)->where('addon_code', $code)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $today))->orderByDesc('created_at')->first();
        if ($item && $item->ends_at !== null) {
            // «Mantener»: deshace el retiro programado sin nuevo cargo.
            $db->table('subscription_items')->where('id', $item->id)->update(['ends_at' => null, 'updated_at' => now()]);
            $this->events->audit($tenantId, 'Suscripción', $sub->code, 'addon_mantener', "Retiro el {$item->ends_at}", $addon->label, $actor);

            return ['message' => "{$addon->label} se mantiene."] + $this->show($tenantId);
        }
        if ($item) {
            throw ApiException::conflict('ADDON_ALREADY_ACTIVE', "{$addon->label} ya está contratado: ajuste la cantidad.");
        }
        $quantity = $addon->quantity_allowed ? max(1, min(100, $quantity)) : 1;
        $unit = BillingMath::addonPrice($code, $sub->plan_id);
        $db->table('subscription_items')->insert(['id' => (string) Str::uuid7(), 'subscription_id' => $sub->id, 'addon_code' => $code, 'quantity' => $quantity,
            'meta' => '{}', 'starts_at' => $today, 'ends_at' => null, 'unit_price' => $unit, 'created_at' => now(), 'updated_at' => now()]);
        $this->bump($sub);
        $invoice = $this->chargeAddon($sub, $addon, $quantity, $unit, $actor);
        EntitlementService::forget($tenantId);
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'addon_contratar', null, "{$addon->label} × {$quantity}".($invoice ? ' · '.$this->invoices->number($invoice) : ''), $actor);
        $this->events->notifyTenant($tenantId, 'suscripcion.addon', "Add-on contratado: {$addon->label}",
            $invoice ? "Emitimos la factura {$this->invoices->number($invoice)} por S/ {$invoice->total} (prorrateo del periodo)." : 'El add-on ya está activo.');
        $this->events->lifecycle($tenantId, 'addon', "{$this->tenantName($tenantId)} contrató {$addon->label}", $sub->status === 'trialing' ? 0 : (float) $unit * $quantity);

        return ['message' => "{$addon->label} contratado.", 'invoice' => $invoice ? $this->invoiceRow($this->invoices->find($invoice->id)) : null] + $this->show($tenantId);
    }

    public function updateAddon(string $tenantId, string $code, int $quantity, User $actor): array
    {
        $db = self::db();
        $sub = $this->current($tenantId);
        $this->assertOperable($sub);
        $addon = $db->table('addons')->where('code', $code)->first() ?? throw ApiException::notFound();
        $item = $db->table('subscription_items')->where('subscription_id', $sub->id)->where('addon_code', $code)->whereNull('ends_at')->first()
            ?? throw ApiException::conflict('ADDON_NOT_ACTIVE', "{$addon->label} no está contratado.");
        if (! $addon->quantity_allowed) {
            throw ApiException::unprocessable('ADDON_QUANTITY_FIXED', "{$addon->label} no admite cantidad.");
        }
        if ($quantity < 1 || $quantity > 100) {
            throw ApiException::invalid('quantity', 'La cantidad va de 1 a 100. Para retirarlo use «Retirar».');
        }
        $current = (int) $item->quantity;
        if ($quantity === $current) {
            throw ApiException::invalid('quantity', 'La cantidad es la misma.');
        }
        if ($quantity < $current) {
            // SUB-BR-06: no se reduce por debajo de lo ya consumido.
            $limits = $this->limits->limits($tenantId);
            foreach (json_decode($addon->grants, true) as $metric => $units) {
                if (! isset(PlatformCatalog::METRICS[$metric]) || $limits[$metric]['value'] === null) {
                    continue;
                }
                $after = $limits[$metric]['value'] - ($current - $quantity) * (int) $units;
                $used = $this->limits->used($tenantId, $metric);
                if ($used > $after) {
                    throw ApiException::unprocessable('SUB-BR-06', PlatformCatalog::metricLabel($metric).": usa {$used} y con {$quantity} paquete(s) el límite sería {$after}. No puede reducir por debajo de lo consumido.");
                }
            }
        }
        $db->table('subscription_items')->where('id', $item->id)->update(['quantity' => $quantity, 'updated_at' => now()]);
        $this->bump($sub);
        $invoice = $quantity > $current ? $this->chargeAddon($sub, $addon, $quantity - $current, (string) $item->unit_price, $actor) : null;
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'addon_ajustar', "{$addon->label} × {$current}", "× {$quantity}", $actor);

        return ['message' => "{$addon->label}: cantidad {$quantity}.", 'invoice' => $invoice ? $this->invoiceRow($this->invoices->find($invoice->id)) : null] + $this->show($tenantId);
    }

    /** Retiro programado: sigue vigente hasta fin de periodo (SUB-BR-06). */
    public function removeAddon(string $tenantId, string $code, User $actor): array
    {
        $db = self::db();
        $sub = $this->current($tenantId);
        $addon = $db->table('addons')->where('code', $code)->first() ?? throw ApiException::notFound();
        $item = $db->table('subscription_items')->where('subscription_id', $sub->id)->where('addon_code', $code)->whereNull('ends_at')->first()
            ?? throw ApiException::conflict('ADDON_NOT_ACTIVE', "{$addon->label} no está contratado.");
        $db->table('subscription_items')->where('id', $item->id)->update(['ends_at' => $sub->current_period_end, 'updated_at' => now()]);
        $this->bump($sub);
        $date = Carbon::parse($sub->current_period_end)->format('d/m/Y');
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'addon_retirar', "{$addon->label} × {$item->quantity}", "Vigente hasta el {$date}", $actor);
        $this->events->lifecycle($tenantId, 'addon', "{$this->tenantName($tenantId)} retiró {$addon->label}", $sub->status === 'trialing' ? 0 : -1 * (float) $item->unit_price * (int) $item->quantity);

        return ['message' => "{$addon->label} se retira al cierre del periodo ({$date})."] + $this->show($tenantId);
    }

    private function chargeAddon(object $sub, object $addon, int $quantity, string $unit, User $actor): ?object
    {
        if ($sub->status === 'trialing') {
            return null;
        }
        $p = BillingMath::prorate($unit, $sub);
        $lineUnit = $p['amount'];
        if (! BigDecimal::of($lineUnit)->isPositive()) {
            return null;
        }
        $invoice = $this->invoices->issue($this->current($sub->tenant_id), 'addon',
            [["Add-on {$addon->label} (prorrateo {$p['days_left']}/{$p['period_days']} días)", $quantity, $lineUnit, 'addon']],
            PlatformClock::today()->toDateString(), $sub->current_period_end);
        $this->invoices->charge($invoice, 'initial', "inv:{$invoice->id}:attempt:1", $actor);

        return $invoice;
    }

    public function redeemCoupon(string $tenantId, string $code, User $actor): array
    {
        $sub = $this->current($tenantId);
        $this->assertOperable($sub);
        $coupon = $this->coupons->redeem($sub, $code, $actor);

        return ['message' => "Cupón {$coupon->code} aplicado: ".CouponService::describe($coupon).' en sus próximas renovaciones.'] + $this->show($tenantId);
    }

    // ───────────────────────────── Facturas y tarjeta ─────────────────────────────

    public function invoiceList(string $tenantId, array $filters = []): array
    {
        return self::db()->table('platform_invoices')->where('tenant_id', $tenantId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('issue_date')->orderByDesc('number')->limit(200)->get()->map(fn ($i) => $this->invoiceRow($i))->all();
    }

    public function invoiceRow(object $i): array
    {
        return ['id' => $i->id, 'number' => $this->invoices->number($i), 'kind' => $i->kind, 'period_from' => $i->period_from, 'period_to' => $i->period_to,
            'issue_date' => $i->issue_date, 'due_date' => $i->due_date, 'subtotal' => (float) $i->subtotal, 'igv' => (float) $i->igv, 'total' => (float) $i->total,
            'status' => $i->status, 'status_label' => PlatformCatalog::INVOICE_STATUS[$i->status], 'sunat_status' => $i->sunat_status,
            'sunat_label' => PlatformCatalog::SUNAT_STATUS[$i->sunat_status], 'paid_at' => $i->paid_at, 'payment_ref' => $i->payment_ref,
            'payable' => in_array($i->status, ['open', 'failed', 'uncollectible'], true), 'next_retry_at' => $i->dunning_next_retry_at];
    }

    public function invoiceDetail(object $invoice): array
    {
        $db = self::db();

        return $this->invoiceRow($invoice) + [
            'bill_to' => json_decode($invoice->bill_to, true), 'issuer' => PlatformInvoiceService::issuer(), 'igv_pct' => (float) $invoice->igv_pct,
            'sunat_hash' => $invoice->sunat_hash, 'void_reason' => $invoice->void_reason,
            'lines' => $db->table('platform_invoice_lines')->where('invoice_id', $invoice->id)->get()->map(fn ($l) => ['description' => $l->description,
                'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'amount' => (float) $l->amount, 'type' => $l->line_type])->all(),
            'attempts' => $db->table('payment_attempts')->where('invoice_id', $invoice->id)->orderBy('attempt_no')->get()->map(fn ($a) => ['attempt_no' => (int) $a->attempt_no,
                'source' => $a->source, 'result' => $a->result, 'gateway_code' => $a->gateway_code, 'message' => $a->message, 'at' => $a->created_at])->all(),
            'credit_note' => ($cn = $db->table('platform_credit_notes')->where('invoice_id', $invoice->id)->first())
                ? ['number' => sprintf('%s-%08d', $cn->series, $cn->number), 'amount' => (float) $cn->amount, 'reason' => $cn->reason, 'refund_ref' => $cn->refund_ref] : null,
        ];
    }

    /** «Pagar ahora» con la tarjeta registrada (SUB-BR-12: la misma Idempotency-Key no genera un segundo cargo). */
    public function pay(string $tenantId, string $number, string $idempotencyKey, User $actor): array
    {
        $invoice = $this->invoices->byNumber($number, $tenantId);
        $result = $this->invoices->charge($invoice, 'portal', "portal:{$tenantId}:{$idempotencyKey}", $actor);
        $ok = $result['attempt']->result === 'succeeded';

        return ['message' => $ok ? "Factura {$number} pagada." : "El cobro fue rechazado: {$result['attempt']->message}.", 'paid' => $ok,
            'invoice' => $this->invoiceDetail($result['invoice'])];
    }

    public function resend(string $tenantId, string $number, User $actor): array
    {
        $invoice = $this->invoices->byNumber($number, $tenantId);
        $sub = $this->current($tenantId);
        $this->events->notifyTenant($tenantId, 'suscripcion.factura', "Factura {$number} de Qhapaq Cloud", "Adjuntamos la factura {$number} por S/ {$invoice->total}.");
        $this->events->audit($tenantId, 'Factura plataforma', $number, 'reenviar', null, "Enviada a {$sub->billing_email}", $actor);

        return ['message' => "Factura reenviada a {$sub->billing_email}."];
    }

    public function setupIntent(string $tenantId): array
    {
        return $this->gateway->createSetupIntent($tenantId);
    }

    /** Guarda el token devuelto por el formulario embebido (nunca PAN ni CVV: PCI DSS SAQ-A). */
    public function setDefaultMethod(string $tenantId, array $data, User $actor): array
    {
        $token = (string) ($data['token'] ?? '');
        if (! preg_match('/^tok_[a-z]+_[a-z]+_(\d{4})_[A-Za-z0-9]+$/', $token, $m)) {
            throw ApiException::invalid('token', 'Token de tarjeta inválido. Vuelva a ingresar la tarjeta.');
        }
        $month = (int) ($data['exp_month'] ?? 0);
        $year = (int) ($data['exp_year'] ?? 0);
        $today = PlatformClock::today();
        if ($month < 1 || $month > 12 || $year < $today->year || ($year === $today->year && $month < $today->month)) {
            throw ApiException::invalid('exp_month', 'La tarjeta está vencida.');
        }
        $brand = Str::lower((string) ($data['brand'] ?? 'visa'));
        $holder = trim((string) ($data['holder'] ?? ''));
        if ($holder === '') {
            throw ApiException::invalid('holder', 'Indique el titular de la tarjeta.');
        }
        $db = self::db();
        $previous = $db->table('payment_methods')->where('tenant_id', $tenantId)->where('is_default', true)->first();
        $db->transaction(function (ConnectionInterface $db) use ($tenantId, $token, $m, $brand, $month, $year, $holder, $actor) {
            $db->table('payment_methods')->where('tenant_id', $tenantId)->where('is_default', true)->update(['is_default' => false, 'status' => 'removed']);
            $db->table('payment_methods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'gateway_token' => $token, 'brand' => Str::limit($brand, 20, ''),
                'last4' => $m[1], 'exp_month' => $month, 'exp_year' => $year, 'holder' => Str::limit($holder, 120, ''), 'is_default' => true, 'status' => 'active',
                'created_by' => $actor->id, 'created_at' => now()]);
        });
        $sub = $this->current($tenantId);
        $label = Str::ucfirst($brand)." •••• {$m[1]}";
        $this->events->audit($tenantId, 'Suscripción', $sub->code, 'metodo_pago', $previous ? Str::ucfirst($previous->brand)." •••• {$previous->last4}" : null, $label, $actor);
        $this->events->notifyTenant($tenantId, 'suscripcion.metodo_pago', 'Tarjeta actualizada', "Los próximos cargos se harán a {$label}.");

        return ['message' => "Tarjeta {$label} registrada."] + $this->show($tenantId);
    }

    // ───────────────────────────── Apoyo ─────────────────────────────

    private function assertOperable(object $sub): void
    {
        if (in_array($sub->status, ['suspended', 'canceled'], true)) {
            throw ApiException::conflict('SUBSCRIPTION_NOT_CHANGEABLE', 'Regularice el pago pendiente antes de modificar la suscripción.');
        }
    }

    private function assertVersion(object $sub, ?string $ifMatch): void
    {
        if ($ifMatch === null || $ifMatch === '') {
            throw new ApiException(428, 'PRECONDITION_REQUIRED', 'SEC-BR-17', 'Este recurso exige el header If-Match con su lock_version');
        }
        if ((int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $sub->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'La suscripción fue modificada por otro usuario; recargue e intente de nuevo');
        }
    }

    private function bump(object $sub): void
    {
        self::db()->table('subscriptions')->where('id', $sub->id)->increment('lock_version', 1, ['updated_at' => now()]);
    }

    private function tenantName(string $tenantId): string
    {
        $t = self::db()->table('tenants')->where('id', $tenantId)->first(['code', 'trade_name', 'legal_name']);

        return "{$t->code} ".($t->trade_name ?? $t->legal_name);
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
