<?php

namespace App\Services\Finance;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Payable;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Support\Carbon;

/**
 * Cuentas por pagar a proveedores (PROC-044): una por servicio confirmado por el proveedor, al costo neto pactado en la
 * reserva (PRD-BR-008/009) y con vencimiento según la condición de pago del contrato. El módulo de Finanzas las programa y paga.
 */
class PayableService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function ensureForService(BookedService $service, ?User $actor): ?Payable
    {
        if (! $service->provider_id || (float) $service->cost_amount <= 0) {
            return null;
        }
        $existing = Payable::where('reservation_service_id', $service->id)->where('status', '<>', 'anulada')->first();
        if ($existing) {
            return $existing;
        }
        $reservation = Reservation::find($service->reservation_id);
        $payable = Payable::create([
            'code' => $this->sequences->next('payable'), 'provider_id' => $service->provider_id, 'reservation_id' => $service->reservation_id,
            'reservation_service_id' => $service->id, 'concept' => mb_substr("{$service->name} · {$service->code}", 0, 200),
            'amount' => $service->cost_amount, 'currency' => $reservation?->currency ?? 'PEN',
            'due_date' => self::dueDate($service->service_date, $service->provider?->payment_terms)->toDateString(), 'status' => Payable::OPEN,
        ]);
        $this->audit->log('Cuenta por pagar', $payable->code, 'crear', null, "{$payable->currency} {$payable->amount} · {$service->provider?->name} · {$service->code}", actor: $actor, system: $actor === null);

        return $payable;
    }

    /** Servicio rechazado o reserva cancelada: las CxP sin pagos se anulan. */
    public function voidFor(array $serviceIds, ?User $actor, string $reason): int
    {
        $count = 0;
        foreach (Payable::whereIn('reservation_service_id', $serviceIds)->whereIn('status', ['abierta', 'aprobada', 'vencida'])->where('paid_amount', 0)->get() as $payable) {
            $payable->forceFill(['status' => 'anulada'])->save();
            $this->audit->log('Cuenta por pagar', $payable->code, 'anular', Payable::STATUS_LABELS['abierta'], "Anulada · {$reason}", actor: $actor, system: $actor === null);
            $count++;
        }

        return $count;
    }

    /** "Crédito 30 días" → +30; "Prepago"/"Contado" → el día del servicio; sin condición → +15. */
    public static function dueDate(Carbon $serviceDate, ?string $terms): Carbon
    {
        $terms = mb_strtolower((string) $terms);
        if (preg_match('/(\d+)\s*d/', $terms, $m)) {
            return $serviceDate->copy()->addDays((int) $m[1]);
        }

        return str_contains($terms, 'prepago') || str_contains($terms, 'contado') ? $serviceDate->copy() : $serviceDate->copy()->addDays(15);
    }

    /** Etiqueta del proveedor (§4.4): vencida por fecha aunque Finanzas aún no la marque. */
    public static function supplierLabel(Payable $payable, string $today): string
    {
        if (in_array($payable->status, ['abierta', 'aprobada'], true) && $payable->due_date->toDateString() < $today) {
            return Payable::SUPPLIER_LABELS['vencida'];
        }

        return Payable::SUPPLIER_LABELS[$payable->status] ?? $payable->status;
    }
}
