<?php

namespace Database\Seeders;

use App\Services\Platform\PlatformClock;
use App\Services\Platform\PlatformProvisioner;
use App\Services\TenantProvisioner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos demo de la plataforma SaaS (spec 13 §11): suscripciones de TNT-0001…0006, facturas de 6 meses (dunning en TNT-0004,
 * recuperación en TNT-0003, vencida en TNT-0006), consumo de Andes (WhatsApp 83 %, API 85 %), cupones, métricas de MRR,
 * API keys, webhooks (WH-0002 con 9 fallos consecutivos) y entregas. Fechas relativas a hoy (America/Lima).
 */
class PlatformBillingDemoSeeder extends Seeder
{
    private ConnectionInterface $db;

    private Carbon $today;

    public function run(): void
    {
        $this->db = DB::connection('pgsql_admin');
        $this->today = PlatformClock::today();
        $provisioner = app(TenantProvisioner::class);
        $andes = $this->tenant('andes');
        $inka = $this->tenant('inka');
        $superAdmin = $this->db->table('platform_users')->value('id');

        $make = fn (string $slug, string $name, string $ruc, string $plan, string $status, string $city) => $provisioner->provision([
            'slug' => $slug, 'legal_name' => "{$name} S.A.C.", 'trade_name' => $name, 'ruc' => $ruc, 'plan_id' => $plan, 'status' => $status,
            'fiscal_address' => "Av. Principal 100, {$city}", 'business_type' => 'Agencia de viajes minorista', 'admin_email' => "admin@{$slug}.test",
            'settings' => ['security' => ['mfa_internal_required' => false]],
        ], "{$city} - Centro", ['sede' => ['name' => "{$city} - Centro", 'city' => $city]]);
        $qori = $make('qori', 'Qori Tours', '20611112222', 'professional', 'activo', 'Arequipa');
        $titicaca = $make('titicaca', 'Titicaca Explorer', '20622223333', 'enterprise', 'activo', 'Puno');
        $amazonia = $make('amazonia', 'Amazonía Lodges', '20633334444', 'professional', 'prueba', 'Iquitos');
        $nazca = $make('nazca', 'Nazca Travel', '20644445555', 'starter', 'activo', 'Ica');

        // TNT-0001 Andes: Enterprise mensual, ~4 días restantes del periodo, tarjeta aprobada.
        $this->subscription($andes->id, 'enterprise', 'monthly', $this->today->copy()->subDays(26), 'active');
        $this->card($andes->id, 'visa', '4242', 'Lucía Paredes');
        $this->history($andes->id, 6, 'paid');
        $this->usage($andes->id, ['whatsapp_month' => 4150, 'email_month' => 31200, 'api_calls_month' => 850000, 'reservations_month' => 212],
            ['whatsapp_month' => [3600, 3900, 4400, 3800, 4700], 'email_month' => [28000, 30500, 33100, 29800, 35200],
                'api_calls_month' => [610000, 702000, 755000, 798000, 912000], 'reservations_month' => [180, 205, 240, 198, 260], 'storage_gb' => [2, 2, 3, 3, 3]]);

        // TNT-0002 Inka: trial Professional con piloto de e-commerce (override).
        $this->db->table('feature_overrides')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $inka->id, 'feature_key' => 'ecommerce', 'value' => true,
            'reason' => 'Piloto de tienda online durante la prueba', 'valid_until' => $this->today->copy()->addDays(30)->toDateString(), 'granted_by' => $superAdmin, 'created_at' => now()]);

        // TNT-0003 Qori: Professional; un cobro fallido en el pasado que se recuperó con el reintento D+1.
        $this->subscription($qori->id, 'professional', 'monthly', $this->today->copy()->subDays(10), 'active');
        $this->card($qori->id, 'mastercard', '4444', 'Andrea Valdivia');
        $this->history($qori->id, 5, 'paid', recoveredAt: 2);

        // TNT-0004 Titicaca: Enterprise con precio pactado S/ 3 150 en cobranza (D+1 ya falló; D+3 vence hoy).
        $sub4 = $this->subscription($titicaca->id, 'enterprise', 'monthly', $this->today->copy()->subDays(3), 'past_due', '3150.00');
        $this->card($titicaca->id, 'visa', '0002', 'Óscar Mamani');
        $this->history($titicaca->id, 5, 'paid', price: '3150.00');
        $inv = $this->invoice($sub4, $this->today->copy()->subDays(3), '3150.00', 'failed');
        $this->db->table('platform_invoices')->where('id', $inv)->update(['dunning_failed_at' => $this->today->copy()->subDays(3)->toDateString(), 'dunning_retries_done' => 1,
            'dunning_next_retry_at' => now()->subHour()]);
        $this->attempts($inv, [['initial', 'declined'], ['dunning', 'declined']]);

        // TNT-0005 Amazonía: trial que vence en 3 días, sin tarjeta.
        $this->db->table('subscriptions')->where('tenant_id', $amazonia->id)->update(['trial_ends_at' => $this->today->copy()->addDays(3)->toDateString(),
            'current_period_end' => $this->today->copy()->addDays(3)->toDateString(), 'trial_starts_at' => $this->today->copy()->subDays(11)->toDateString(),
            'current_period_start' => $this->today->copy()->subDays(11)->toDateString()]);
        $this->db->table('tenants')->where('id', $amazonia->id)->update(['trial_ends_at' => $this->today->copy()->addDays(3)]);

        // TNT-0006 Nazca: Starter, factura vencida tras el último reintento → suspendido por cobranza.
        $sub6 = $this->subscription($nazca->id, 'starter', 'monthly', $this->today->copy()->subDays(20), 'suspended');
        $this->card($nazca->id, 'visa', '9995', 'Luis Ramos');
        $this->history($nazca->id, 4, 'paid');
        $inv6 = $this->invoice($sub6, $this->today->copy()->subDays(20), '490.00', 'uncollectible');
        $this->db->table('platform_invoices')->where('id', $inv6)->update(['dunning_failed_at' => $this->today->copy()->subDays(20)->toDateString(), 'dunning_retries_done' => 3]);
        $this->attempts($inv6, [['initial', 'declined'], ['dunning', 'declined'], ['dunning', 'declined'], ['dunning', 'declined']]);
        $this->db->table('tenants')->where('id', $nazca->id)->update(['status' => 'suspendido', 'suspension_source' => 'billing', 'suspended_at' => now()->subDays(13),
            'suspend_reason' => 'Cobranza: factura impaga tras el último reintento']);

        // Cupones.
        foreach ([
            ['BIENVENIDA25', 'percent', 25, 3, [], 50, 12, 90, 'active', 'Campaña de bienvenida'],
            ['ANUAL10', 'percent', 10, 12, ['professional', 'enterprise'], 100, 7, 180, 'active', 'Incentivo al ciclo anual'],
            ['FERIA2026', 'fixed', 200, 1, ['starter'], 30, 30, 60, 'active', 'Feria de turismo (agotado)'],
            ['INVIERNO26', 'percent', 15, 2, [], 40, 9, -20, 'inactive', 'Campaña de invierno (vencida)'],
        ] as [$code, $type, $value, $cycles, $plans, $max, $redeemed, $days, $status, $note]) {
            $this->db->table('coupons')->insert(['id' => (string) Str::uuid7(), 'code' => $code, 'type' => $type, 'value' => $value, 'duration_cycles' => $cycles,
                'plan_ids' => json_encode($plans), 'max_redemptions' => $max, 'redeemed_count' => $redeemed, 'valid_to' => $this->today->copy()->addDays($days)->toDateString(),
                'status' => $status, 'note' => $note, 'created_by' => $superAdmin, 'created_at' => now()->subDays(60)]);
        }

        // Métricas: cierre de cada mes (6 meses) y eventos del ciclo de vida.
        $mrr = [6170, 6840, 7380, 8010, 8570, 9150];
        foreach ($mrr as $i => $value) {
            $day = $this->today->copy()->startOfMonth()->subMonthsNoOverflow(6 - $i)->endOfMonth();
            $this->db->table('platform_metrics_daily')->insert(['day' => $day->toDateString(), 'mrr' => $value, 'arr' => $value * 12, 'arpa' => round($value / 4, 2),
                'active_tenants' => 4, 'trialing_tenants' => 2, 'new_tenants' => $i % 2 ? 1 : 0, 'churned_tenants' => $i === 3 ? 1 : 0, 'logo_churn_pct' => 4.5,
                'revenue_churn_pct' => 3.1, 'failed_payments' => $i === 5 ? 1 : 0,
                'mrr_by_plan' => json_encode(['starter' => 490, 'professional' => 1190 + 400 * ($i % 3), 'enterprise' => $value - 1680 - 400 * ($i % 3)])]);
        }
        foreach ([
            [$qori->id, 'new', 'Qori Tours convirtió su trial a Professional', 1190, 150],
            [$titicaca->id, 'new', 'Titicaca Explorer contrató Enterprise (precio pactado S/ 3 150)', 3150, 120],
            [$nazca->id, 'new', 'Nazca Travel contrató Starter', 490, 110],
            [$andes->id, 'upgrade', 'Andes Travel: Professional → Enterprise', 1700, 90],
            [$qori->id, 'recovered', 'Qori Tours: cobro recuperado en el reintento D+1', 0, 55],
            [$nazca->id, 'suspended', 'Nazca Travel suspendido por cobranza', 0, 13],
            [$titicaca->id, 'payment_failed', 'Titicaca Explorer: cobro de renovación rechazado', 0, 3],
        ] as [$tenant, $kind, $description, $delta, $daysAgo]) {
            $this->db->table('platform_events')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'kind' => $kind, 'description' => $description,
                'mrr_delta' => $delta, 'created_at' => now()->subDays($daysAgo)]);
        }
        foreach ([$andes->id => 2890, $qori->id => 1190, $titicaca->id => 3150, $nazca->id => 490] as $tenant => $value) {
            $this->db->table('tenants')->where('id', $tenant)->update(['mrr' => $value]);
        }
        $this->db->table('tenants')->where('id', $titicaca->id)->update(['storage_used_bytes' => (int) (41.7 * 1024 ** 3)]);
        $this->db->table('tenants')->where('id', $qori->id)->update(['storage_used_bytes' => (int) (8.2 * 1024 ** 3)]);

        $this->integrations($andes->id);
    }

    /** API keys, webhooks (WH-0002 a un fallo de deshabilitarse), entregas y una app OAuth de Andes. */
    private function integrations(string $tenant): void
    {
        $admin = $this->db->table('iam_users')->where('tenant_id', $tenant)->where('email', 'admin@andes.test')->value('id');
        foreach ([
            ['KEY-0001', 'Web de reservas', 'live', ['reservations:read', 'catalog:read', 'leads:write'], null, null, 'active', 2],
            ['KEY-0002', 'ERP contable', 'live', ['reservations:read', 'clients:read'], ['190.42.0.0/16'], $this->today->copy()->addMonths(6), 'active', 30],
            ['KEY-0003', 'Pruebas de integración', 'test', ['reservations:read'], null, null, 'revoked', null],
        ] as [$code, $name, $env, $scopes, $ips, $expires, $status, $usedMinutes]) {
            $token = "qts_{$env}_".Str::random(32);
            $this->db->table('integration_api_keys')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'code' => $code, 'name' => $name, 'environment' => $env,
                'prefix' => substr($token, 0, 13), 'last4' => substr($token, -4), 'token_hash' => hash('sha256', $token), 'scopes' => json_encode($scopes),
                'ip_allowlist' => $ips ? json_encode($ips) : null, 'expires_at' => $expires?->toDateString(), 'status' => $status,
                'last_used_at' => $usedMinutes ? now()->subMinutes($usedMinutes) : null, 'last_used_ip' => $usedMinutes ? '190.42.18.7' : null,
                'revoked_at' => $status === 'revoked' ? now()->subDays(12) : null, 'revoked_by' => $status === 'revoked' ? $admin : null,
                'revoke_reason' => $status === 'revoked' ? 'Fin de las pruebas del proveedor' : null, 'created_by' => $admin, 'created_at' => now()->subDays(40)]);
        }
        $hooks = [];
        foreach ([
            ['WH-0001', 'https://hooks.sandbox.test/andes/reservas', 'CRM de reservas', ['reservation.created', 'reservation.confirmed', 'reservation.cancelled', 'quote.approved'], 0],
            ['WH-0002', 'https://webhook.test/erp/fail', 'ERP contable (receptor caído)', ['payment.applied', 'invoice.issued'], 9],
        ] as [$code, $url, $description, $events, $failures]) {
            $id = (string) Str::uuid7();
            $secret = 'whsec_'.Str::random(32);
            $this->db->table('integration_webhooks')->insert(['id' => $id, 'tenant_id' => $tenant, 'code' => $code, 'url' => $url, 'description' => $description,
                'events' => json_encode($events), 'secret_encrypted' => Crypt::encryptString($secret), 'secret_prefix' => substr($secret, 0, 10), 'api_version' => '2026-09-01',
                'status' => 'active', 'consecutive_failures' => $failures, 'created_by' => $admin, 'created_at' => now()->subDays(35), 'updated_at' => now()]);
            $hooks[$code] = [$id, $events, $failures];
        }
        $n = 0;
        foreach ($hooks as $code => [$id, $events, $failures]) {
            $count = $failures ? 9 : 6;
            for ($i = 0; $i < $count; $i++) {
                $n++;
                $failed = $failures > 0;
                $event = $events[$i % count($events)];
                $eventId = 'evt_'.Str::lower((string) Str::ulid());
                $at = now()->subHours(($count - $i) * 5);
                $this->db->table('integration_webhook_deliveries')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'code' => sprintf('WHD-%06d', $n),
                    'webhook_id' => $id, 'event_type' => $event, 'event_id' => $eventId, 'entity_code' => $failed ? sprintf('PAG-2026-%05d', 300 + $i) : sprintf('RES-2026-%06d', 340 + $i),
                    'payload' => json_encode(['id' => $eventId, 'type' => $event, 'api_version' => '2026-09-01', 'created_at' => $at->toIso8601String(), 'tenant_id' => $tenant,
                        'data' => ['code' => $failed ? sprintf('PAG-2026-%05d', 300 + $i) : sprintf('RES-2026-%06d', 340 + $i)]]),
                    'attempt' => $failed ? 6 : 1, 'status' => $failed ? 'failed' : 'delivered', 'http_status' => $failed ? 500 : 200, 'duration_ms' => $failed ? 912 : 140 + $i * 17,
                    'response_excerpt' => $failed ? '{"error":"Internal Server Error"}' : '{"received":true}', 'error' => $failed ? 'HTTP 500' : null, 'manual' => false,
                    'created_at' => $at, 'updated_at' => $at]);
            }
        }
        $this->db->table('integration_oauth_apps')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'code' => 'APP-0001', 'name' => 'Integración CRM Partner',
            'vendor' => 'Partner CRM S.A.C.', 'client_id' => 'qoa_'.Str::lower(Str::random(24)), 'secret_hash' => hash('sha256', Str::random(40)), 'secret_prefix' => 'qos_'.Str::random(6),
            'redirect_uris' => json_encode(['https://crm.partner.test/oauth/callback']), 'allowed_scopes' => json_encode(['reservations:read', 'clients:read']), 'status' => 'active',
            'created_by' => $admin, 'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);
        foreach (['api_key' => 4, 'webhook' => 3, 'webhook_delivery' => $n + 1, 'oauth_app' => 2] as $docType => $next) {
            $this->db->table('sys_sequences')->where('tenant_id', $tenant)->where('doc_type', $docType)->update(['next_number' => $next]);
        }
    }

    private function subscription(string $tenant, string $plan, string $cycle, Carbon $start, string $status, ?string $negotiated = null): object
    {
        $end = $cycle === 'annual' ? $start->copy()->addYearNoOverflow()->subDay() : $start->copy()->addMonthNoOverflow()->subDay();
        $this->db->table('subscriptions')->where('tenant_id', $tenant)->update(['plan_id' => $plan, 'cycle' => $cycle, 'status' => $status, 'trial_starts_at' => null,
            'trial_ends_at' => null, 'current_period_start' => $start->toDateString(), 'current_period_end' => $end->toDateString(), 'billing_anchor_day' => min(28, $start->day),
            'negotiated_price' => $negotiated, 'created_at' => now()->subMonths(7)]);

        return $this->db->table('subscriptions')->where('tenant_id', $tenant)->first();
    }

    private function card(string $tenant, string $brand, string $last4, string $holder): void
    {
        $this->db->table('payment_methods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'gateway_token' => "tok_sandbox_{$brand}_{$last4}_demo".Str::random(8),
            'brand' => $brand, 'last4' => $last4, 'exp_month' => 12, 'exp_year' => $this->today->year + 2, 'holder' => $holder, 'is_default' => true, 'status' => 'active',
            'created_at' => now()->subMonths(6)]);
    }

    /** Facturas de los `$months` periodos anteriores al actual (más la del periodo actual), pagadas. `$recoveredAt` = periodo que falló y se recuperó. */
    private function history(string $tenant, int $months, string $status, ?string $price = null, ?int $recoveredAt = null): void
    {
        $sub = $this->db->table('subscriptions')->where('tenant_id', $tenant)->first();
        $price ??= (string) $this->db->table('plans')->where('id', $sub->plan_id)->value('price_monthly');
        $start = Carbon::parse($sub->current_period_start, PlatformClock::TZ);
        for ($i = $months; $i >= 0; $i--) {
            $from = $start->copy()->subMonthsNoOverflow($i);
            if ($i === 0 && $sub->status !== 'active') {
                continue;
            }
            $id = $this->invoice($sub, $from, $price, $status);
            if ($recoveredAt === $i) {
                $this->db->table('platform_invoices')->where('id', $id)->update(['dunning_failed_at' => $from->toDateString(), 'dunning_retries_done' => 1]);
                $this->attempts($id, [['initial', 'declined'], ['dunning', 'succeeded']]);
            } else {
                $this->attempts($id, [['initial', 'succeeded']]);
            }
        }
    }

    private function invoice(object $sub, Carbon $from, string $price, string $status): string
    {
        $plan = $this->db->table('plans')->where('id', $sub->plan_id)->value('name');
        $to = $from->copy()->addMonthNoOverflow()->subDay();
        $igv = round((float) $price * 0.18, 2);
        $id = (string) Str::uuid7();
        $number = PlatformProvisioner::nextNumber($this->db, 'FQ01');
        $this->db->table('platform_invoices')->insert(['id' => $id, 'series' => 'FQ01', 'number' => $number, 'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id,
            'kind' => 'subscription', 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'issue_date' => $from->toDateString(),
            'due_date' => $from->toDateString(), 'currency' => 'PEN', 'subtotal' => $price, 'igv' => $igv, 'total' => round((float) $price + $igv, 2), 'igv_pct' => '18.00',
            'status' => $status, 'paid_at' => $status === 'paid' ? $from->copy()->setTime(6, 5) : null, 'payment_ref' => $status === 'paid' ? 'ch_'.Str::lower(Str::random(20)) : null,
            'bill_to' => json_encode(['ruc' => $sub->billing_ruc, 'name' => $sub->billing_name, 'address' => $sub->billing_address, 'ubigeo' => $sub->billing_ubigeo, 'email' => $sub->billing_email]),
            'sunat_status' => 'accepted', 'sunat_hash' => base64_encode(hash('sha256', "FQ01-{$number}", true)),
            'pdf_path' => sprintf('platform/invoices/%d/FQ01-%08d.pdf', $from->year, $number), 'xml_path' => sprintf('platform/invoices/%d/FQ01-%08d.xml', $from->year, $number),
            'created_at' => $from->copy()->setTime(6, 0), 'updated_at' => $from->copy()->setTime(6, 5)]);
        $this->db->table('platform_invoice_lines')->insert(['id' => (string) Str::uuid7(), 'invoice_id' => $id,
            'description' => "Plan {$plan} mensual · {$from->format('d/m/Y')} al {$to->format('d/m/Y')}", 'quantity' => 1, 'unit_price' => $price, 'amount' => $price, 'line_type' => 'plan']);

        return $id;
    }

    /** @param list<array{0: string, 1: string}> $attempts */
    private function attempts(string $invoice, array $attempts): void
    {
        $codes = ['succeeded' => [null, 'Tarjeta aprobada'], 'declined' => ['card_declined', 'Tarjeta rechazada por el banco emisor']];
        foreach ($attempts as $i => [$source, $result]) {
            $this->db->table('payment_attempts')->insert(['id' => (string) Str::uuid7(), 'invoice_id' => $invoice, 'attempt_no' => $i + 1, 'source' => $source, 'result' => $result,
                'gateway_code' => $codes[$result][0], 'message' => $codes[$result][1], 'idempotency_key' => "seed:{$invoice}:{$i}", 'created_at' => now()->subDays(max(0, 3 - $i))]);
        }
    }

    /**
     * Contadores del mes en curso (repartidos hasta hoy) y consolidado de los 5 meses anteriores.
     *
     * @param  array<string, int>  $current
     * @param  array<string, list<int>>  $past
     */
    private function usage(string $tenant, array $current, array $past): void
    {
        $first = $this->today->copy()->startOfMonth();
        $days = (int) $first->diffInDays($this->today) + 1;
        foreach ($current as $metric => $total) {
            $per = intdiv($total, (int) $days);
            for ($d = 0; $d < $days; $d++) {
                $value = $d === $days - 1 ? $total - $per * ($days - 1) : $per;
                $this->db->table('usage_counters')->insert(['tenant_id' => $tenant, 'metric' => $metric, 'day' => $first->copy()->addDays($d)->toDateString(), 'value' => $value]);
            }
        }
        $limits = $this->db->table('plan_limits')->where('plan_id', $this->db->table('tenants')->where('id', $tenant)->value('plan_id'))->pluck('value', 'metric');
        foreach ($past as $metric => $values) {
            foreach ($values as $i => $value) {
                $month = $first->copy()->subMonthsNoOverflow(5 - $i);
                $this->db->table('usage_monthly')->insert(['tenant_id' => $tenant, 'metric' => $metric, 'month' => $month->toDateString(), 'value' => $value,
                    'limit_value' => $limits[$metric] ?? null, 'alert_80_sent_at' => $limits[$metric] && $value >= 0.8 * $limits[$metric] ? $month->copy()->addDays(20) : null]);
            }
        }
    }

    private function tenant(string $slug): object
    {
        return $this->db->table('tenants')->where('slug', $slug)->first();
    }
}
