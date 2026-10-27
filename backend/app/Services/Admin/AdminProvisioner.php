<?php

namespace App\Services\Admin;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Configuración inicial de Administración por tenant (spec 09 §3.8–3.14): settings, reglas de notificación, adapters y jobs. */
class AdminProvisioner
{
    /** @param array<string, array<string, mixed>> $overrides valores del asistente de alta por sección */
    public static function seedTenant(string $tenantId, ConnectionInterface $db, array $overrides = []): void
    {
        $now = now();
        $tenant = $db->table('tenants')->where('id', $tenantId)->first();
        $mainSede = $db->table('iam_sedes')->where('tenant_id', $tenantId)->where('is_main', true)->value('id');
        $legacy = json_decode((string) ($tenant->settings ?? '{}'), true) ?: [];

        $values = AdminCatalog::SETTINGS;
        $values['general']['main_sede_id'] = $mainSede;
        $values['general']['legal_rep'] = $tenant->legal_rep ?? null;
        $values['general']['address'] = $tenant->fiscal_address ?? null;
        $values['currency']['alt_currency'] = $tenant->alt_currency ?? 'USD';
        $values['currency']['fx_rate'] = number_format((float) data_get($legacy, 'fx.USD', 3.75), 6, '.', '');
        $values['currency']['tax_pct'] = (string) (float) ($tenant->igv_pct ?? 18);
        $values['policies']['quote_validity_days'] = (int) data_get($legacy, 'sales.quote_validity_days', 10);
        $values['policies']['hold_minutes'] = (int) data_get($legacy, 'catalog.hold_minutes', 30);
        $rank = AdminCatalog::PLANS[$tenant->plan_id ?? 'professional'][1] ?? 2;
        foreach (AdminCatalog::CHANNELS as $key => [, $min]) {
            $values['channels'][$key] = $values['channels'][$key] && $rank >= AdminCatalog::PLANS[$min][1];
        }
        foreach ($overrides as $section => $data) {
            $values[$section] = array_merge($values[$section] ?? [], $data);
        }
        foreach ($values as $section => $data) {
            $db->table('sys_settings')->insertOrIgnore(['tenant_id' => $tenantId, 'section' => $section, 'values' => json_encode($data), 'updated_at' => $now, 'lock_version' => 0]);
        }

        foreach (AdminCatalog::EVENTS as $code => [$label, $e, $w, $p, $po, $recipients]) {
            $db->table('notif_event_rules')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'event_code' => $code, 'label' => $label,
                'ch_email' => $e, 'ch_whatsapp' => $w, 'ch_push' => $p, 'ch_portal' => $po, 'recipients' => $recipients, 'timing' => 'immediate', 'condition' => 'always', 'updated_at' => $now]);
        }

        foreach (AdminCatalog::ADAPTERS as $code => [$name, $kind, $status, $env, $note]) {
            $db->table('integration_adapters')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'kind' => $kind,
                'status' => $status, 'environment' => $env, 'note' => $note, 'secret_ref' => $status === 'activo' ? "vault://tenants/{$tenantId}/".Str::lower($code) : null,
                'secret_rotated_at' => $status === 'activo' ? $now->copy()->subDays(40) : null, 'created_at' => $now, 'updated_at' => $now]);
        }

        self::ensureSystemJobs($tenantId, $db, array_keys(AdminCatalog::JOBS), $now);
    }

    /**
     * Crea los jobs de sistema que falten. Los reportes programados numeran después de los jobs de sistema; si uno ya tomó un
     * código que ahora es de sistema (tenants anteriores a una spec que agrega jobs), pasa al siguiente código libre.
     */
    public static function ensureSystemJobs(string $tenantId, ConnectionInterface $db, array $codes, ?Carbon $now = null): void
    {
        $now ??= now();
        foreach ($codes as $code) {
            $taken = $db->table('sys_scheduled_jobs')->where('tenant_id', $tenantId)->where('code', $code)->whereNotNull('report_key')->first();
            if ($taken) {
                $max = $db->table('sys_scheduled_jobs')->where('tenant_id', $tenantId)->pluck('code')->map(fn ($c) => (int) Str::after($c, 'JOB-'))->max();
                $db->table('sys_scheduled_jobs')->where('id', $taken->id)->update(['code' => sprintf('JOB-%02d', max($max + 1, self::firstReportJob()))]);
            }
            [$name, $cron, $label, $queue] = AdminCatalog::JOBS[$code];
            $db->table('sys_scheduled_jobs')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
                'job_class' => 'App\\Jobs\\Scheduled\\'.Str::studly(Str::slug($name, '_')), 'cron' => $cron, 'cron_label' => $label, 'queue' => $queue,
                'next_run_at' => CronSchedule::next($cron, $now), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** Primer número libre para reportes programados: después del último job de sistema. */
    public static function firstReportJob(): int
    {
        return count(AdminCatalog::JOBS) + 1;
    }
}
