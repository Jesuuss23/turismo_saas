<?php

namespace App\Console\Commands;

use App\Models\Iam\User;
use App\Services\Crm\ActivityService;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * JOB-04 (spec 01 §8.2), cada hora: marca vencidas las actividades pendientes y recuerda al
 * responsable las vencidas o del día. Recorre los tenants activos con su propio contexto (RLS).
 */
class RemindCrmActivities extends Command
{
    protected $signature = 'crm:activities-remind';

    protected $description = 'JOB-04: vencimiento y recordatorio de actividades CRM';

    public function handle(TenantContext $context): int
    {
        $tenants = DB::connection('pgsql_admin')->table('tenants')->where('status', 'activo')->pluck('id');

        foreach ($tenants as $tenantId) {
            $context->run($tenantId, function () {
                $byOwner = app(ActivityService::class)->markOverdue();
                $notify = app(Notify::class);
                foreach ($byOwner as $ownerId => $activities) {
                    $owner = User::where('status', User::ACTIVE)->find($ownerId);
                    if ($owner === null) {
                        continue;
                    }
                    $overdue = collect($activities)->filter(fn ($a) => $a->due_at->isPast())->count();
                    $notify->push('actividad.recordatorio', 'portal', $owner,
                        count($activities).' actividad(es) para hoy o vencidas'.($overdue ? " ({$overdue} vencidas)" : ''),
                        collect($activities)->map(fn ($a) => "{$a->code} · {$a->subject}")->implode("\n"),
                        ['activity_codes' => collect($activities)->pluck('code')->all()]);
                }
            });
        }

        $this->info('Recordatorios CRM procesados en '.count($tenants).' tenant(s).');

        return self::SUCCESS;
    }
}
