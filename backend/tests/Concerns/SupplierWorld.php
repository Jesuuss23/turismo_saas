<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Escenario de la extranet sobre OpsWorld: el proveedor del catálogo (PeruRail: tren + guía de la reserva) con su usuario `prv`
 * y el hotel Inti Wasi con su usuario `hotel` (proveedor B del mismo tenant).
 */
trait SupplierWorld
{
    public function supplierWorld(): void
    {
        $this->opsWorld();
        $this->addUser('prv', 'provider', ['provider_id' => $this->provider->id, 'provider_role' => 'admin']);
        $this->addUser('hotel', 'provider', ['provider_id' => $this->hotel->id]);
    }

    /** Servicio de la reserva del escenario por tipo operativo (tren_entrada | tour). */
    public function serviceOf(string $kind = 'tren_entrada', ?string $reservationId = null): string
    {
        return $this->admin()->table('booking_services')->where('reservation_id', $reservationId ?? $this->res['id'])->where('service_kind', $kind)->value('id');
    }

    /** Operaciones envía la solicitud al proveedor (desde la orden de la reserva). */
    public function requestService(string $serviceId): void
    {
        if (! $this->admin()->table('ops_orders')->where('reservation_id', $this->res['id'])->exists()) {
            $this->makeOrder();
        }
        $this->oPost('ops', "reservation-services/{$serviceId}/supplier-request", [], $this->idem())->assertOk();
    }

    public function sGet(string $who, string $uri): TestResponse
    {
        return $this->qGet($who, 'supplier/'.ltrim($uri, '/'));
    }

    public function sPost(string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->qPost($who, 'supplier/'.ltrim($uri, '/'), $data, $headers + $this->idem());
    }

    public function invoiceFile(string $name = 'F001-123.pdf', string $mime = 'application/pdf'): array
    {
        return ['file_name' => $name, 'mime_type' => $mime, 'content_base64' => base64_encode('%PDF-1.4 factura de prueba')];
    }

    /** Voucher emitido por Operaciones para un servicio ya confirmado, con fecha de servicio relativa a hoy. */
    public function issuedVoucher(string $serviceId, int $days = 0): array
    {
        $v = $this->oPost('ops', 'vouchers', ['reservation_service_id' => $serviceId], $this->idem())->assertCreated()->json('data');
        $this->admin()->table('ops_vouchers')->where('id', $v['id'])->update(['service_date' => now('America/Lima')->addDays($days)->toDateString()]);

        return $v;
    }

    /** Rooming de la reserva del escenario en el hotel (2 habitaciones), confirmada y enviada por Operaciones. */
    public function sentRooming(): array
    {
        $this->makeOrder();
        $pax = $this->admin()->table('booking_passengers')->where('reservation_id', $this->res['id'])->orderBy('seq')->pluck('id')->all();
        $list = $this->oPost('ops', 'rooming-lists', ['reservation_id' => $this->res['code'], 'hotel_provider_id' => $this->hotel->id,
            'check_in' => now()->addDays(20)->toDateString(), 'check_out' => now()->addDays(23)->toDateString()])->assertCreated()->json('data');
        $lock = fn () => $this->lockOf('ops_rooming_lists', $list['id']);
        $this->oPost('ops', "rooming-lists/{$list['id']}/rooms", ['room_number' => '201', 'room_type' => 'Simple', 'passenger_ids' => [$pax[0]]], $lock())->assertCreated();
        $this->oPost('ops', "rooming-lists/{$list['id']}/rooms", ['room_number' => '202', 'room_type' => 'Simple', 'passenger_ids' => [$pax[1]]], $lock())->assertCreated();
        $this->oPost('ops', "rooming-lists/{$list['id']}/confirm", [], $lock())->assertOk();
        $this->oPost('ops', "rooming-lists/{$list['id']}/send", [], $lock() + $this->idem())->assertOk();

        return $list;
    }
}
