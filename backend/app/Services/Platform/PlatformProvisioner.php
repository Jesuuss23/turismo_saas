<?php

namespace App\Services\Platform;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Alta del tenant en la plataforma SaaS (spec 13, PROC-001): correlativos de la API y suscripción inicial (`trialing` si está en prueba). */
class PlatformProvisioner
{
    /** doc_type => [etiqueta, prefijo, relleno]. */
    public const SEQUENCES = [
        'api_key' => ['API key', 'KEY-', 4],
        'webhook' => ['Webhook', 'WH-', 4],
        'webhook_delivery' => ['Entrega de webhook', 'WHD-', 6],
        'oauth_app' => ['Aplicación OAuth', 'APP-', 4],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad]) {
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label, 'series' => 'default',
                'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => 1, 'year_reset' => false, 'updated_at' => $now,
            ]);
        }
    }

    /** Suscripción viva del tenant según su estado: prueba → trialing; activo → active; suspendido → suspended. Idempotente. */
    public static function subscribe(object $tenant, ConnectionInterface $db): string
    {
        $existing = $db->table('subscriptions')->where('tenant_id', $tenant->id)->where('status', '<>', 'canceled')->value('id');
        if ($existing) {
            return $existing;
        }
        $today = PlatformClock::today();
        $trial = $tenant->status === 'prueba';
        $trialEnds = $trial ? ($tenant->trial_ends_at ? Carbon::parse($tenant->trial_ends_at)->toDateString() : $today->copy()->addDays(14)->toDateString()) : null;
        // Fin de periodo inclusivo: el último día cubierto (la renovación corre el día siguiente, §8.2).
        $start = $today->copy();
        $end = $trial ? Carbon::parse($trialEnds) : $start->copy()->addMonthNoOverflow()->subDay();
        if ($end->lte($start)) {
            $end = $start->copy()->addDay();
        }
        $id = (string) Str::uuid7();
        $db->table('subscriptions')->insert([
            'id' => $id, 'code' => self::nextCode($db, 'SUB', $today), 'tenant_id' => $tenant->id, 'plan_id' => $tenant->plan_id ?? 'professional', 'cycle' => 'monthly',
            'status' => match ($tenant->status) {
                'prueba' => 'trialing', 'suspendido' => 'suspended', default => 'active'
            },
            'trial_starts_at' => $trial ? $today->toDateString() : null, 'trial_ends_at' => $trialEnds,
            'current_period_start' => $start->toDateString(), 'current_period_end' => $end->toDateString(), 'billing_anchor_day' => min(28, $start->day),
            'auto_renew' => true, 'billing_ruc' => preg_match('/^(10|15|17|20)\d{9}$/', (string) $tenant->ruc) ? $tenant->ruc : '20000000000',
            'billing_name' => $tenant->legal_name, 'billing_address' => $tenant->fiscal_address ?? 'Por completar',
            'billing_email' => $tenant->admin_email ?? "facturacion@{$tenant->slug}.test", 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** SUB-2026-0001: correlativo global con bloqueo de fila. */
    public static function nextCode(ConnectionInterface $db, string $series, Carbon $today): string
    {
        return sprintf('SUB-%d-%04d', $today->year, self::nextNumber($db, $series));
    }

    public static function nextNumber(ConnectionInterface $db, string $series): int
    {
        return (int) $db->selectOne('UPDATE platform_sequences SET next_number = next_number + 1 WHERE series = ? RETURNING next_number - 1 AS n', [$series])->n;
    }
}
