<?php

namespace App\Services\Inventory;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Inventory\Allotment;
use App\Models\Inventory\AllotmentConsumption;
use App\Models\Inventory\AllotmentDay;
use App\Models\Inventory\AllotmentEvent;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Proyección del consumo de cupos (spec 11 §3.3, §8): reemplaza al listener `ConsumeAllotmentOnServiceChange`. Cada cambio de
 * un servicio de reserva o del estado de la reserva recalcula sus consumos y el `used_qty`/`pending_qty` de las fechas tocadas.
 */
class AllotmentConsumer
{
    /** CUP-BR-008: estados del servicio que consumen cupo; solicitado/pendiente quedan "en trámite". */
    public const CONSUMES = [BookedService::CONFIRMED, BookedService::COMPLETED, BookedService::IN_PROGRESS];

    public const IN_PROCESS = [BookedService::PENDING, BookedService::REQUESTED];

    public static function register(): void
    {
        $run = fn (callable $fn) => app(TenantContext::class)->has() ? $fn(app(self::class)) : null;
        BookedService::saved(function (BookedService $s) use ($run) {
            if ($s->wasRecentlyCreated || $s->wasChanged(['status', 'service_date', 'pax', 'catalog_service_id'])) {
                $run(fn (self $c) => $c->sync($s));
            }
        });
        BookedService::deleted(fn (BookedService $s) => $run(fn (self $c) => $c->sync($s, true)));
        Reservation::updated(function (Reservation $r) use ($run) {
            if ($r->wasChanged('status')) {
                $run(fn (self $c) => $c->syncReservation($r));
            }
        });
        Passenger::saved(function (Passenger $p) use ($run) {
            if ($p->wasRecentlyCreated || $p->wasChanged('pax_type')) {
                $run(fn (self $c) => $c->syncReservation(Reservation::find($p->reservation_id)));
            }
        });
    }

    public function __construct(private readonly Notify $notify) {}

    public function syncReservation(?Reservation $reservation): void
    {
        if (! $reservation) {
            return;
        }
        BookedService::where('reservation_id', $reservation->id)->whereNotNull('catalog_service_id')->get()->each(fn (BookedService $s) => $this->sync($s));
    }

    /** Recalcula todos los consumos de los servicios del catálogo del contrato (alta, edición o datos previos al módulo). */
    public function backfill(Allotment $allotment): void
    {
        BookedService::where('catalog_service_id', $allotment->service_id)
            ->whereBetween('service_date', [$allotment->valid_from->toDateString(), $allotment->valid_to->toDateString()])
            ->get()->each(fn (BookedService $s) => $this->sync($s));
        // Consumos de fechas que ya no existen o servicios fuera de vigencia.
        AllotmentConsumption::whereIn('allotment_day_id', AllotmentDay::where('allotment_id', $allotment->id)->select('id'))->where('state', '<>', 'revertido')
            ->whereNotIn('booking_service_id', BookedService::where('catalog_service_id', $allotment->service_id)->select('id'))
            ->get()->each(function (AllotmentConsumption $c) {
                $c->forceFill(['state' => 'revertido'])->save();
                $this->recompute($c->allotment_day_id);
            });
    }

    public function sync(BookedService $service, bool $deleted = false): void
    {
        $reservation = $deleted ? null : Reservation::find($service->reservation_id);
        $state = $deleted ? null : $this->state($service, $reservation);
        $day = $service->catalog_service_id && $service->service_date ? $this->dayFor($service) : null;
        $existing = AllotmentConsumption::where('booking_service_id', $service->id)->get();
        $touched = $existing->pluck('allotment_day_id')->all();

        DB::transaction(function () use ($service, $state, $day, $existing, &$touched) {
            foreach ($existing as $c) {
                if (($c->allotment_day_id !== $day?->id || $state === null) && $c->state !== 'revertido') {
                    $c->forceFill(['state' => 'revertido'])->save();
                }
            }
            if ($day && $state !== null) {
                $qty = $this->units($service, $day->allotment->kind);
                $row = $existing->firstWhere('allotment_day_id', $day->id);
                if ($row) {
                    $row->forceFill(['qty' => $qty, 'state' => $state])->save();
                } elseif ($day->allotment->status !== 'liberado') { // un contrato liberado no acepta consumo nuevo
                    AllotmentConsumption::create(['allotment_day_id' => $day->id, 'booking_service_id' => $service->id,
                        'reservation_id' => $service->reservation_id, 'qty' => $qty, 'state' => $state]);
                }
                $touched[] = $day->id;
            }
        });

        foreach (array_unique($touched) as $dayId) {
            $this->recompute($dayId, $service->code);
        }
    }

    private function state(BookedService $service, ?Reservation $reservation): ?string
    {
        if (! $reservation || in_array($reservation->status, [Reservation::CANCELLED, Reservation::DRAFT], true)) {
            return null;
        }

        return match (true) {
            in_array($service->status, self::CONSUMES, true) => 'consumido',
            in_array($service->status, self::IN_PROCESS, true) => 'en_tramite',
            default => null,
        };
    }

    private function dayFor(BookedService $service): ?AllotmentDay
    {
        return AllotmentDay::with('allotment')->whereDate('service_date', $service->service_date->toDateString())
            ->whereHas('allotment', fn ($q) => $q->where('service_id', $service->catalog_service_id)->whereIn('status', [...Allotment::LIVE, 'vencido', 'liberado']))
            ->orderByRaw("(SELECT CASE WHEN a.status IN ('activo','en_release') THEN 0 ELSE 1 END FROM catalog_allotments a WHERE a.id = catalog_allotment_days.allotment_id)")
            ->first();
    }

    /**
     * Unidades (§3.3): tren/entrada → pasajeros no INF; hotel → habitaciones dobles (pasajeros no INF / 2) por la noche del servicio.
     * `booking_services` aún no tiene `rooms`/`nights`: cada fila de alojamiento es una noche (supuesto 6 de §12).
     */
    public function units(BookedService $service, string $kind): int
    {
        $infants = Passenger::where('reservation_id', $service->reservation_id)->where('pax_type', 'INF')->count();
        $pax = (int) ($service->pax ?: Passenger::where('reservation_id', $service->reservation_id)->count());
        $people = max(1, $pax > $infants ? $pax - $infants : $pax);

        return $kind === 'hotel' ? (int) ceil($people / 2) : $people;
    }

    /** Proyección con `Cache::lock("allot:{id}:{date}")`; si queda disponible < 0 avisa la sobreventa (CUP-BR-006). */
    private function recompute(string $dayId, ?string $serviceCode = null): void
    {
        $day = AllotmentDay::with('allotment')->find($dayId);
        if (! $day) {
            return;
        }
        Cache::lock("allot:{$day->allotment_id}:{$day->service_date->toDateString()}", 10)->block(5, function () use ($day, $serviceCode) {
            $before = $day->available();
            $sums = AllotmentConsumption::where('allotment_day_id', $day->id)
                ->selectRaw("COALESCE(SUM(qty) FILTER (WHERE state = 'consumido'), 0) AS used, COALESCE(SUM(qty) FILTER (WHERE state = 'en_tramite'), 0) AS pending")->first();
            AllotmentDay::whereKey($day->id)->update(['used_qty' => (int) $sums->used, 'pending_qty' => (int) $sums->pending, 'updated_at' => now()]);
            $day->refresh();
            if ($day->available() < 0 && $before >= 0) {
                $a = $day->allotment;
                $text = "Sobreventa {$day->service_date->format('d/m')}: disponible {$day->available()}".($serviceCode ? " tras confirmar {$serviceCode}" : '');
                AllotmentEvent::create(['allotment_id' => $a->id, 'occurred_at' => now(), 'user_id' => auth()->id(), 'message' => mb_substr($text, 0, 300)]);
                foreach (CatalogRecipients::operations() as $user) {
                    $this->notify->push('cupo.sobreventa', 'portal', $user, "Sobreventa en {$a->code}", "{$a->name} · {$text}",
                        ['related_type' => 'catalog_allotments', 'related_id' => $a->id, 'url' => "/catalogo/cupos/{$a->code}"]);
                }
            }
            app(AllotmentService::class)->resolveStatus($day->allotment);
        });
    }
}
