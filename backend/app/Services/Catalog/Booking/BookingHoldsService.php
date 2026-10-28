<?php

namespace App\Services\Catalog\Booking;

use App\Domain\Bookings\AvailabilityCache;
use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Bookings\RuleResolver;
use App\Events\Bookings\ReservationCancelled;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Hold;
use App\Models\Catalog\Product;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Bookings\ReceivableService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jobs del motor (spec 16 §8.2): `ExpireCheckoutHolds` (R-HOLD) y `ReleasePayLaterHolds` (R-PAY-LATER) cada 5 min —un hold
 * vencido de una reserva online sin pagos la anula y devuelve los cupos— y `EvaluateMinimumGroup` (R-MIN-GROUP) diario.
 */
class BookingHoldsService
{
    public function __construct(
        private readonly RuleResolver $rules,
        private readonly AvailabilityCache $cache,
        private readonly ReceivableService $receivables,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
    ) {}

    /** @return list<string> refs de holds procesados */
    public function expire(): array
    {
        $done = [];
        $holds = Hold::where('reference_type', AvailabilityCalculator::ENGINE_HOLD)->where('status', 'hold')->where('expires_at', '<', now())->orderBy('expires_at')->get();
        foreach ($holds as $hold) {
            DB::transaction(function () use ($hold, &$done) {
                $reservation = Reservation::whereKey($hold->reference_id)->lockForUpdate()->first();
                $paid = $reservation && $reservation->receivables()->where(fn ($q) => $q->where('status', 'pagada')->orWhere('status', 'parcial'))->exists();
                if ($reservation && $reservation->status === Reservation::PENDING_PAYMENT && ! $paid) {
                    $this->cancelUnpaid($reservation, $hold);
                    $hold->update(['status' => 'expirada']);
                } else {
                    // Pagada o ya gestionada: el cupo quedó en la reserva.
                    $hold->update(['status' => 'consumida']);
                }
                $this->audit->log('Disponibilidad', $hold->ref, $hold->status === 'expirada' ? 'expirar' : 'consumir', 'hold', $hold->status, system: true);
                $done[] = $hold->ref;
                if ($hold->product_id) {
                    $this->cache->bump($hold->product_id);
                }
            });
        }

        return $done;
    }

    /** R-MIN-GROUP: a `decisionDays` de la fecha, si no se alcanza `minPax`, Operaciones propone reprogramar. @return list<string> */
    public function evaluateMinimumGroup(): array
    {
        if (! $this->rules->tenantOn('R-MIN-GROUP')) {
            return [];
        }
        $out = [];
        $today = CarbonImmutable::parse($this->clock->today()->toDateString());
        $products = Product::where('status', Product::ACTIVE)->whereHas('availabilityRanges')->get();
        foreach ($products as $p) {
            if (! $this->rules->on('R-MIN-GROUP', $p)) {
                continue;
            }
            $params = $this->rules->params('R-MIN-GROUP', $p);
            $date = $today->addDays((int) ($params['decisionDays'] ?? 7))->toDateString();
            $sold = app(AvailabilityCalculator::class)->sold($p, $date, $date)[$date] ?? 0;
            if ($sold > 0 && $sold < (int) ($params['minPax'] ?? 2)) {
                $out[] = "{$p->ref} {$date}: {$sold}/{$params['minPax']}";
                foreach (CatalogRecipients::operations() as $user) {
                    $this->notify->push('disponibilidad.grupo_minimo', 'portal', $user, "Grupo mínimo no alcanzado: {$p->ref} {$date}",
                        "{$sold} pax vendidos de {$params['minPax']} requeridos. Proponer reprogramación a los clientes.", ['related_type' => 'catalog_product', 'related_id' => $p->id]);
                }
            }
        }

        return $out;
    }

    private function cancelUnpaid(Reservation $reservation, Hold $hold): void
    {
        $actor = User::find($reservation->owner_id);
        $reservation->services()->whereNotIn('status', BookedService::INACTIVE)->update(['status' => BookedService::CANCELLED, 'updated_at' => now()]);
        if ($actor) {
            $this->receivables->cancelOpen($reservation, $actor);
        }
        $before = Reservation::STATUS_LABELS[$reservation->status];
        $reservation->forceFill(['status' => Reservation::CANCELLED, 'cancel_reason' => "Hold {$hold->ref} vencido sin pago (reserva online)", 'cancelled_at' => now()])->save();
        $this->audit->log('Reserva', $reservation->code, 'cancelar', $before, "Cancelada · hold {$hold->ref} vencido sin pago", system: true);
        ReservationCancelled::dispatch($reservation->id);
    }
}
