<?php

namespace Database\Seeders;

use App\Models\Iam\User;
use App\Services\Admin\CronSchedule;
use App\Services\Reports\FileWriter;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo de Administración (spec 09) para “andes”: log de notificaciones con un fallo de WhatsApp (131026), error ERR-0418
 * de JOB-07 (transitorio, intento 3/5), un envío programado JOB-20, exportaciones del historial y una invitación pendiente.
 */
class AdminDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class)->id();
        $now = now();
        $user = fn (string $code) => User::where('code', $code)->first();
        $manager = $user('U02');
        $carla = $user('U03');

        // Sedes con responsable y dirección (§3.6).
        DB::table('iam_sedes')->where('tenant_id', $tenant)->where('code', 'S01')->update(['manager_user_id' => $user('U01')->id, 'phone' => '+51 1 445 0120']);
        DB::table('iam_sedes')->where('tenant_id', $tenant)->where('code', 'S02')->update(['manager_user_id' => $user('U05')->id]);

        // Log de notificaciones (§3.12): entregados (uno leído en el portal) y el fallo permanente de WhatsApp con su reenvío pendiente.
        $log = [
            ['reserva.confirmada', 'email', $carla, 'enviado', 'Reserva RES-2026-000347 confirmada', null, 3],
            ['cotizacion.enviada', 'whatsapp', null, 'enviado', 'Su cotización COT-2026-000412', null, 5],
            ['pago.recibido', 'portal', $user('U07'), 'enviado', 'Pago recibido PAG-2026-000188', null, 8],
            ['asignacion.creada', 'push', $user('U09'), 'enviado', 'Nueva asignación: City tour Cusco', null, 12],
        ];
        foreach ($log as [$event, $channel, $to, $status, $subject, $error, $hours]) {
            DB::table('notif_messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'user_id' => $to?->id, 'event' => $event, 'channel' => $channel,
                'recipient' => $to ? ($channel === 'email' ? $to->email : $to->name) : '+51 984 112 233', 'subject' => $subject, 'body' => $subject, 'data' => '{}', 'status' => $status,
                'retries' => 0, 'last_error' => $error, 'sent_at' => $now->copy()->subHours($hours), 'read_at' => $channel === 'portal' ? $now->copy()->subHours($hours - 1) : null,
                'provider_message_id' => 'msg_'.Str::lower(Str::random(10)), 'created_at' => $now->copy()->subHours($hours), 'updated_at' => $now->copy()->subHours($hours)]);
        }
        $failed = (string) Str::uuid7();
        DB::table('notif_messages')->insert(['id' => $failed, 'tenant_id' => $tenant, 'user_id' => null, 'event' => 'reserva.confirmada', 'channel' => 'whatsapp',
            'recipient' => '+1 415 555 0134', 'subject' => 'Reserva RES-2026-000347 confirmada', 'body' => 'Hola John, su reserva RES-2026-000347 está confirmada.', 'data' => '{}',
            'status' => 'fallido', 'retries' => 1, 'last_error' => 'Código 131026: número no registrado en el entorno sandbox', 'created_at' => $now->copy()->subHours(2), 'updated_at' => $now->copy()->subHours(2)]);
        DB::table('integration_errors')->insert(['id' => (string) Str::uuid7(), 'code' => 417, 'tenant_id' => $tenant, 'source' => 'notification', 'ref_code' => 'reserva.confirmada',
            'target' => 'WhatsApp +1 415 555 0134', 'error' => 'Código 131026: número no registrado en el entorno sandbox', 'error_class' => 'permanente', 'attempt' => 1, 'max_attempts' => 1,
            'status' => 'fallo_definitivo', 'created_at' => $now->copy()->subHours(2), 'updated_at' => $now->copy()->subHours(2)]);

        // ERR-0418: sincronización del adapter tren con 3 de 5 intentos (§8.2 JOB-07).
        DB::table('integration_errors')->insert(['id' => (string) Str::uuid7(), 'code' => 418, 'tenant_id' => $tenant, 'source' => 'job', 'ref_code' => 'JOB-07',
            'target' => 'Adapter tren (Ferrovía Sur Andina)', 'error' => 'Timeout 30 s al consultar disponibilidad de cupos', 'error_class' => 'transitorio', 'attempt' => 3, 'max_attempts' => 5,
            'next_retry_at' => $now->copy()->addMinutes(8), 'status' => 'reintentando', 'created_at' => $now->copy()->subHours(5), 'updated_at' => $now->copy()->subMinutes(20)]);
        DB::statement("SELECT setval('integration_errors_code_seq', 418)");
        DB::table('sys_scheduled_jobs')->where('tenant_id', $tenant)->where('code', 'JOB-07')
            ->update(['last_run_at' => $now->copy()->subMinutes(20), 'last_status' => 'error', 'last_result' => 'Timeout 30 s (intento 3/5)']);
        DB::table('sys_scheduled_jobs')->where('tenant_id', $tenant)->where('code', 'JOB-06')
            ->update(['last_run_at' => $now->copy()->subHour(), 'last_status' => 'advertencia', 'last_result' => '2 servicio(s) pendientes de confirmación del proveedor']);
        foreach (['JOB-01', 'JOB-02', 'JOB-03', 'JOB-04', 'JOB-05', 'JOB-08', 'JOB-09'] as $code) {
            DB::table('sys_scheduled_jobs')->where('tenant_id', $tenant)->where('code', $code)->update(['last_run_at' => $now->copy()->subHours(1), 'last_status' => 'ok', 'last_result' => 'Ejecutado sin novedades']);
        }
        DB::table('integration_adapters')->where('tenant_id', $tenant)->where('code', 'INT-06')->update(['owner_user_id' => $user('U05')->id, 'last_test_at' => $now->copy()->subMinutes(20),
            'last_test_ok' => false, 'last_test_result' => 'Timeout 30 s']);

        // Envío programado JOB-20 (EXP-BR-03; JOB-10..19 son jobs de sistema (specs 11, 12 y 14)) y exportaciones del historial (PROC-061).
        [$cron, $label] = CronSchedule::forReport('lunes', '08:00');
        $job = (string) Str::uuid7();
        DB::table('sys_scheduled_jobs')->insert(['id' => $job, 'tenant_id' => $tenant, 'code' => 'JOB-20', 'name' => 'Reporte programado: Comercial / CRM', 'job_class' => 'GenerateScheduledReport',
            'cron' => $cron, 'cron_label' => $label, 'queue' => 'reports', 'paused' => false, 'next_run_at' => CronSchedule::next($cron), 'report_key' => 'comercial', 'report_format' => 'PDF',
            'recipients' => 'gerencia@andes.test, admin@andes.test', 'owner_user_id' => $manager->id, 'is_system' => false, 'created_at' => $now, 'updated_at' => $now]);
        $disk = Storage::disk(config('turismo.exports_disk'));
        foreach ([
            ['Finanzas y rentabilidad · Últimos 6 meses', 'finanzas', 'XLSX', $manager, 1, 'disponible'],
            ['Comercial / CRM · Mes actual (programado)', 'comercial', 'PDF', null, 5, 'disponible'],
            ['Cotizaciones · Últimos 3 meses', 'cotizaciones', 'CSV', $manager, 9, 'disponible'],
        ] as $i => [$name, $key, $format, $by, $days, $status]) {
            $id = (string) Str::uuid7();
            $path = "tenants/{$tenant}/exports/{$id}.".strtolower($format);
            $bytes = FileWriter::write($format, $name, ['Reporte', 'Generado'], [[$name, $now->copy()->subDays($days)->format('d/m/Y H:i')]]);
            $disk->put($path, $bytes);
            DB::table('report_exports')->insert(['id' => $id, 'code' => 31 + $i, 'tenant_id' => $tenant, 'report_name' => $name, 'report_key' => $key, 'filters' => '{}', 'scope' => 'filtered',
                'format' => $format, 'rows_count' => 1, 'size_bytes' => strlen($bytes), 'file_path' => $path, 'status' => $status, 'requested_by' => $by?->id,
                'scheduled_job_id' => $by ? null : $job, 'expires_at' => $now->copy()->subDays($days)->addDays(7), 'created_at' => $now->copy()->subDays($days), 'finished_at' => $now->copy()->subDays($days)]);
        }
        DB::statement("SELECT setval('report_exports_code_seq', 33)");

        // Invitación pendiente (USR: pendiente → activo al aceptar).
        $pending = User::create(['code' => 'U19', 'name' => 'Valeria Ríos', 'email' => 'valeria.ventas@andes.test', 'password' => null,
            'sede_id' => DB::table('iam_sedes')->where('tenant_id', $tenant)->where('code', 'S02')->value('id'), 'status' => User::PENDING, 'mfa_required' => true,
            'invited_at' => $now->copy()->subDay(), 'invited_by' => $user('U01')->id]);
        $pending->assignRole('sales');
        DB::table('iam_user_invitations')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'user_id' => $pending->id, 'token_hash' => hash('sha256', 'demo-invitacion-valeria'),
            'expires_at' => $now->copy()->addHours(48), 'require_mfa' => true, 'invited_by' => $user('U01')->id, 'resent_count' => 0, 'created_at' => $now->copy()->subDay()]);
    }
}
