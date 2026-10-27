<?php

namespace App\Console\Commands;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Sys\NotifMessage;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Supplier\RateRequests;
use App\Services\Supplier\SupplierRecipients;
use App\Services\Supplier\SupplierRequests;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Jobs de la extranet (spec 06 §8):
 * - `remind` (cada 2 h): RemindPendingSupplierRequests — solicitudes sin respuesta > 24 h o con servicio a ≤ 48 h.
 * - `daily`: ExpireSupplierAlternativeHolds, ProviderContractExpiryAlerts (< 60 días) y nuevas vigencias de tarifa que empiezan hoy.
 */
class SupplierMonitor extends Command
{
    protected $signature = 'supplier:monitor {mode : remind|daily} {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Extranet de proveedores: recordatorios de solicitudes, bloqueos vencidos, contratos por vencer y vigencias de tarifa';

    public function handle(TenantContext $context): int
    {
        $mode = $this->argument('mode');
        if (! in_array($mode, ['remind', 'daily'], true)) {
            $this->error('Modo inválido.');

            return self::INVALID;
        }
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line("{$mode}: ".$this->{$mode}()));
        }

        return self::SUCCESS;
    }

    private function remind(): string
    {
        $now = app(CrmClock::class)->now();
        $recipients = app(SupplierRecipients::class);
        $sent = 0;
        $pending = BookedService::where('status', BookedService::REQUESTED)->whereNotNull('provider_id')
            ->whereHas('reservation', fn ($q) => $q->whereNotIn('status', [Reservation::DRAFT, ...Reservation::CLOSED]))
            ->where(fn ($q) => $q->where('requested_at', '<', $now->copy()->subDay())
                ->orWhereBetween('service_date', [$now->toDateString(), $now->copy()->addDays(2)->toDateString()]))
            ->with(['provider', 'reservation'])->get();
        foreach ($pending as $s) {
            $ref = "{$s->id}:{$now->toDateString()}";
            if (! $s->provider || NotifMessage::where('event', 'proveedor.recordatorio')->where('data->ref', $ref)->exists()) {
                continue;
            }
            $data = ['related_type' => 'reservation', 'related_id' => $s->reservation_id, 'ref' => $ref];
            $recipients->toProvider($s->provider, 'supplier.requests.respond', 'proveedor.recordatorio', "Solicitud pendiente de respuesta · {$s->reservation?->code}",
                "{$s->name} · {$s->service_date->toDateString()}. Responda en la extranet (objetivo: 24 h).", $data);
            $recipients->toOperations('proveedor.recordatorio', $s->reservation_id, "{$s->provider->name} no responde · {$s->code}", "{$s->name} · {$s->service_date->toDateString()}", $data);
            $sent++;
        }

        return "{$sent} recordatorio(s)";
    }

    private function daily(): string
    {
        $expired = app(SupplierRequests::class)->expireHolds();
        $rates = app(RateRequests::class)->applyDue();
        $today = app(CrmClock::class)->today();
        $alerts = 0;
        foreach (Provider::where('status', 'activo')->whereBetween('contract_end', [$today->toDateString(), $today->copy()->addDays(59)->toDateString()])->get() as $provider) {
            $ref = "{$provider->id}:{$provider->contract_end->toDateString()}";
            if (NotifMessage::where('event', 'proveedor.contrato_por_vencer')->where('data->ref', $ref)->exists()) {
                continue;
            }
            $body = "El contrato vence el {$provider->contract_end->format('d/m/Y')}.";
            $data = ['related_type' => 'provider', 'related_id' => $provider->id, 'ref' => $ref];
            app(SupplierRecipients::class)->toProvider($provider, 'supplier.portal.view', 'proveedor.contrato_por_vencer', 'Su contrato con la agencia está por vencer', $body, $data);
            foreach (CatalogRecipients::role(['operations', 'manager']) as $user) {
                app(Notify::class)->push('proveedor.contrato_por_vencer', 'portal', $user, "Contrato por vencer · {$provider->name}", $body, $data);
            }
            $alerts++;
        }

        return "{$expired} alternativa(s) expirada(s); {$rates} vigencia(s) de tarifa aplicada(s); {$alerts} contrato(s) por vencer";
    }
}
