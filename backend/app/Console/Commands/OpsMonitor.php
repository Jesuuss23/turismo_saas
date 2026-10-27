<?php

namespace App\Console\Commands;

use App\Models\Bookings\BookedService;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Order;
use App\Models\Ops\SyncAction;
use App\Models\Sys\NotifMessage;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Ops\AssignmentService;
use App\Services\Ops\IncidentService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jobs de Operaciones (spec 05 §8.3), un comando con modos:
 * - `sla` (cada 5 min): IncidentSlaMonitor.
 * - `watch` (cada 30 min): asignaciones sin confirmar a ≤ 24 h (JOB-09) y servicios pendientes de proveedor a ≤ 3 días (JOB-06).
 * - `daily` (05:00): recordatorio 48 h al pasajero (JOB-05) y documentos de recursos ≤ 30 días.
 * - `purge` (diario): retención de 90 días de `ops_sync_actions`.
 */
class OpsMonitor extends Command
{
    protected $signature = 'ops:monitor {mode : sla|watch|daily|purge}';

    protected $description = 'Monitoreo de Operaciones: SLA de incidencias, asignaciones y proveedores pendientes, recordatorios y purga';

    public function handle(TenantContext $context): int
    {
        $mode = $this->argument('mode');
        if (! in_array($mode, ['sla', 'watch', 'daily', 'purge'], true)) {
            $this->error('Modo inválido.');

            return self::INVALID;
        }
        foreach (DB::connection('pgsql_admin')->table('tenants')->where('status', 'activo')->pluck('id') as $tenantId) {
            $context->run($tenantId, fn () => $this->line("{$mode}: ".$this->{$mode}()));
        }

        return self::SUCCESS;
    }

    private function sla(): string
    {
        return app(IncidentService::class)->monitorSla().' incidencia(s) con SLA vencido';
    }

    private function watch(): string
    {
        $clock = app(CrmClock::class);
        $now = $clock->now();
        $active = fn ($q) => $q->whereNotIn('status', [Order::CANCELLED, Order::COMPLETED]);
        $alerts = 0;
        $unconfirmed = Assignment::where('status', Assignment::ASSIGNED)->whereHas('order', $active)->with('order.coordinator')
            ->whereBetween('service_date', [$now->toDateString(), $now->copy()->addDay()->toDateString()])->get()
            ->filter(fn (Assignment $a) => $a->service_date->copy()->setTimeFromTimeString((string) ($a->service_time ?? '08:00'))->diffInHours($now, true) <= 24);
        foreach ($unconfirmed as $a) {
            $alerts += (int) $this->once('asignacion.sin_confirmar', $a->id, $a->order?->coordinator, "Asignación sin confirmar · {$a->order?->code}",
                "{$a->service_label} · {$a->service_date->toDateString()} ".substr((string) $a->service_time, 0, 5), ['related_type' => 'ops_order', 'related_id' => $a->order_id]);
        }
        $orders = Order::where($active)->with('coordinator')->get()->keyBy('reservation_id');
        $pending = BookedService::whereIn('reservation_id', $orders->keys())->whereIn('status', [BookedService::PENDING, BookedService::REQUESTED])
            ->whereNotNull('provider_id')->whereBetween('service_date', [$now->toDateString(), $now->copy()->addDays(3)->toDateString()])->get();
        foreach ($pending as $s) {
            $order = $orders[$s->reservation_id];
            $alerts += (int) $this->once('proveedor.pendiente', $s->id, $order->coordinator, "Proveedor sin confirmar · {$order->code}",
                "{$s->code} · {$s->name} · {$s->service_date->toDateString()}", ['related_type' => 'ops_order', 'related_id' => $order->id]);
        }

        return "{$alerts} alerta(s)";
    }

    private function daily(): string
    {
        $clock = app(CrmClock::class);
        $notify = app(Notify::class);
        $target = $clock->today()->addDays(2)->toDateString();
        $sent = 0;
        foreach (Order::where('date_from', $target)->whereNotIn('status', [Order::CANCELLED])->with('reservation')->get() as $order) {
            foreach (User::where('reservation_id', $order->reservation_id)->get() as $pax) {
                $notify->push('viaje.recordatorio_48h', 'push', $pax, 'Su viaje comienza en 48 horas', "{$order->reservation?->title} · revise su itinerario y vouchers en la app.",
                    ['related_type' => 'reservation', 'related_id' => $order->reservation_id, 'reservation_id' => $order->reservation_id]);
                $sent++;
            }
        }
        $docs = app(AssignmentService::class)->documentAlerts();
        if ($docs) {
            $body = collect($docs)->map(fn ($d) => "{$d['document']} {$d['name']}: ".($d['expired'] ? 'vencido' : 'vence').' '.$d['expires_on'])->implode("\n");
            foreach (CatalogRecipients::role(['operations', 'manager']) as $user) {
                $notify->push('recursos.documentos', 'portal', $user, 'Licencias y SOAT por vencer', $body);
            }
        }

        return "{$sent} recordatorio(s) 48 h; ".count($docs).' documento(s) por vencer';
    }

    private function purge(): string
    {
        return SyncAction::where('received_at', '<', now()->subDays(90))->delete().' acción(es) de sincronización purgadas';
    }

    /** Una alerta por evento y registro (evita repetirla cada 30 min). */
    private function once(string $event, string $id, ?User $to, string $subject, string $body, array $data): bool
    {
        if (! $to || NotifMessage::where('event', $event)->where('data->ref', $id)->exists()) {
            return false;
        }
        app(Notify::class)->push($event, 'portal', $to, $subject, $body, $data + ['ref' => $id]);

        return true;
    }
}
