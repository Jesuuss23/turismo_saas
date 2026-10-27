<?php

namespace App\Services\Channels;

use App\Events\Bookings\ReservationCancelled;
use App\Events\Bookings\ReservationConfirmed;
use App\Events\Bookings\ReservationCreated;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Ops\Voucher;
use App\Services\AuditLogger;
use App\Services\Bookings\ReceivableService;
use App\Services\Bookings\ReservationService;
use App\Services\Bookings\ServiceBreakdown;
use App\Services\Catalog\AvailabilityService;
use App\Services\Ops\VoucherService;
use App\Services\Quotes\QuotePricing;
use App\Services\SequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reservas que nacen en un canal (spec 08 §4.5, §11-23): B2B y e-commerce crean la reserva sin cotización, con la salida por FK,
 * consumo de cupos, pasajeros nominados o por nominar y una sola CxC por el total. Se llama dentro del lock de la salida.
 */
class ChannelReservations
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReceivableService $receivables,
        private readonly AvailabilityService $availability,
        private readonly QuotePricing $pricing,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * @param  array{product: Product, departure: ?Departure, date: string, adt: int, chd: int, client_id: string, owner: User, channel: string, status: string,
     *               currency: string, total: string, cost: string, title: string, agency_id?: ?string, b2b_terms?: ?array, ecom_order_id?: ?string,
     *               passengers: list<array{name: ?string, doc_type?: ?string, doc: ?string, type?: string, email?: ?string, phone?: ?string}>,
     *               receivable: array{concept: string, due: string, kind: string}, audit_action: string, origin: string}  $o
     * @return array{0: Reservation, 1: Receivable}
     */
    public function create(array $o, User $actor): array
    {
        $product = $o['product'];
        $departure = $o['departure'];
        $pax = $o['adt'] + $o['chd'];
        $reservation = Reservation::create([
            'code' => $this->sequences->next('reservation'), 'client_id' => $o['client_id'], 'owner_id' => $o['owner']->id, 'sede_id' => $o['owner']->sede_id,
            'title' => mb_substr($o['title'], 0, 200), 'status' => $o['status'], 'channel' => $o['channel'], 'agency_id' => $o['agency_id'] ?? null,
            'ecom_order_id' => $o['ecom_order_id'] ?? null, 'b2b_terms' => $o['b2b_terms'] ?? null,
            'travel_from' => $o['date'], 'travel_to' => $this->travelTo($product, $o['date']), 'currency' => $o['currency'], 'fx_rate' => $this->pricing->fx(),
            'total_amount' => $o['total'], 'snapshot_label' => "{$product->ref} v".($product->currentVersion?->version ?? '1.0'),
            'policies_snapshot' => $product->policies ? [$product->id => ReservationService::policyOf($product)] : [],
        ]);
        if ($departure) {
            $hold = $this->availability->bookLocked($departure, $pax, $reservation->code, $reservation->id, $actor);
            $this->audit->log('Disponibilidad', $departure->ref, $o['status'] === Reservation::PENDING_PAYMENT && $o['channel'] === 'E-commerce' ? 'retener_cupos' : 'consumir_cupos',
                (string) ($departure->sold - $pax).'/'.$departure->capacity, "{$departure->sold}/{$departure->capacity} · {$reservation->code} · {$hold->ref}", actor: $actor);
        }
        $rows = ServiceBreakdown::splitTotals($product, $pax, $o['cost'], $o['total'], $o['currency'], $this->pricing->fx());
        $services = $this->reservations->persistServices($reservation, $rows, $product, $o['date'], $pax, $departure?->id, null);
        $this->setServices($reservation, $o['status'], $actor, $services->all());
        $this->passengers($reservation, $o['adt'], $o['chd'], $o['passengers']);
        $receivable = $this->receivables->create($reservation, $o['receivable']['concept'], $o['total'], $o['receivable']['due'], $actor, $o['origin'], $o['receivable']['kind']);
        $this->audit->log('Reserva', $reservation->code, $o['audit_action'], null,
            Reservation::STATUS_LABELS[$o['status']]." · canal {$o['channel']} · {$product->ref} · {$o['currency']} {$o['total']}".(isset($o['b2b_terms']['pay_mode']) ? " · {$o['b2b_terms']['pay_mode']}" : ''), actor: $actor);
        ReservationCreated::dispatch($reservation->id);
        if ($o['status'] === Reservation::CONFIRMED) {
            ReservationConfirmed::dispatch($reservation->id);
        }

        return [$reservation->fresh(), $receivable];
    }

    /** Estado de servicios según el de la reserva: confirmada → confirmado con código; si no, solicitado al proveedor. */
    public function setServices(Reservation $reservation, string $status, User $actor, ?array $services = null): void
    {
        $services ??= $reservation->services()->whereNotIn('status', BookedService::INACTIVE)->get()->all();
        foreach ($services as $s) {
            $s->forceFill($status === Reservation::CONFIRMED
                ? ['status' => BookedService::CONFIRMED, 'confirmation_code' => 'AH-'.random_int(100000, 999999), 'confirmed_at' => now(), 'confirmed_by_name' => 'Cupo propio (salida)']
                : ['status' => BookedService::REQUESTED, 'requested_at' => now()])->save();
        }
    }

    /** RT-11: voucher v1 por servicio confirmado (si el proveedor no está habilitado queda pendiente para Operaciones). @return list<Voucher> */
    public function issueVouchers(Reservation $reservation, User $actor, string $origin): array
    {
        $issued = [];
        foreach ($reservation->services()->where('status', BookedService::CONFIRMED)->get() as $s) {
            try {
                $issued[] = $this->vouchers->issue($s, "Emisión automática ({$origin})", $actor, $origin);
            } catch (Throwable) {
                // Sin proveedor habilitado o ya emitido: Operaciones lo resuelve desde la reserva.
            }
        }

        return $issued;
    }

    /** E-commerce: pago aprobado → confirmada (con salida) o pendiente de proveedor (bajo solicitud). */
    public function confirmPaid(Reservation $reservation, User $actor): Reservation
    {
        $to = $reservation->services()->whereNotNull('departure_id')->exists() ? Reservation::CONFIRMED : Reservation::PENDING_PROVIDER;
        $before = $reservation->status;
        $reservation->forceFill(['status' => $to])->save();
        $this->setServices($reservation, $to, $actor);
        $this->audit->log('Reserva', $reservation->code, 'cambio_estado', Reservation::STATUS_LABELS[$before], Reservation::STATUS_LABELS[$to].' · pago aprobado', actor: $actor);
        if ($to === Reservation::CONFIRMED) {
            ReservationConfirmed::dispatch($reservation->id);
        }

        return $reservation->fresh();
    }

    /** Anulación/expiración del pedido (ECO-BR-11/14): libera cupos, cancela reserva, servicios y CxC sin pagos. */
    public function cancel(Reservation $reservation, string $reason, ?User $actor, bool $system = false): void
    {
        foreach ($reservation->services()->whereNotNull('departure_id')->pluck('departure_id')->unique() as $depId) {
            $departure = Departure::whereKey($depId)->lockForUpdate()->first();
            if ($departure) {
                $released = $this->availability->releaseLocked($departure, $reservation->id, $reason, $actor ?? $reservation->owner);
                $this->audit->log('Disponibilidad', $departure->ref, 'liberar_cupos', null, "{$released} cupo(s) · {$reservation->code} · {$reason}", actor: $actor, system: $system);
            }
        }
        $before = $reservation->status;
        $reservation->forceFill(['status' => Reservation::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        $reservation->services()->whereNotIn('status', BookedService::INACTIVE)->update(['status' => BookedService::CANCELLED]);
        $this->receivables->cancelOpen($reservation, $actor ?? $reservation->owner);
        $this->audit->log('Reserva', $reservation->code, 'cancelar', Reservation::STATUS_LABELS[$before], "Cancelada · {$reason}", actor: $actor, system: $system);
        ReservationCancelled::dispatch($reservation->id);
    }

    /** B2B-BR-13: una línea "Nombre — Documento" por pasajero; los faltantes quedan por nominar. */
    private function passengers(Reservation $reservation, int $adt, int $chd, array $given): void
    {
        $seq = 0;
        foreach (['ADT' => $adt, 'CHD' => $chd] as $type => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $g = array_shift($given) ?? [];
                $name = trim((string) ($g['name'] ?? ''));
                $seq++;
                Passenger::create(['reservation_id' => $reservation->id, 'seq' => $seq, 'pax_type' => $g['type'] ?? $type,
                    'full_name' => $name !== '' ? mb_substr($name, 0, 150) : "Pasajero {$seq} (por nominar)",
                    'doc_type' => $g['doc_type'] ?? null, 'doc_number' => trim((string) ($g['doc'] ?? '')) ?: null,
                    'email' => $g['email'] ?? null, 'phone' => $g['phone'] ?? null, 'is_lead' => $seq === 1 && $name !== '', 'is_placeholder' => $name === '']);
            }
        }
    }

    private function travelTo(Product $product, string $date): string
    {
        $days = $product->duration_unit === 'días' ? max(1, (int) ceil((float) $product->duration_value)) : 1;

        return Carbon::parse($date)->addDays($days - 1)->toDateString();
    }

    public static function token(): string
    {
        return Str::random(40);
    }
}
