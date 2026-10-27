<?php

namespace Tests\Concerns;

use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Models\Ops\Driver;
use App\Models\Ops\Guide;
use App\Models\Ops\Vehicle;
use App\Services\Catalog\ProviderService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Escenario de Operaciones sobre ReservationWorld: una reserva confirmada (tren + guía, el guía requiere recursos),
 * dos guías (uno vinculado a un usuario guía), un conductor con usuario, dos vehículos, un hotel y un pasajero.
 */
trait OpsWorld
{
    /** @var array<string, mixed> */
    protected array $res = [];

    /** @var array<string, Guide|Driver|Vehicle> */
    protected array $rsc = [];

    protected Provider $hotel;

    public function opsWorld(): void
    {
        $this->reservationWorld();
        $this->res = $this->makeReservation('u03');
        $this->inTenant(function () {
            $this->rsc['g1'] = Guide::create(['code' => 'G01', 'full_name' => 'Wilber Condori', 'languages' => ['es', 'en'], 'license_number' => 'GT-1', 'license_expires_on' => now()->addYear()->toDateString()]);
            $this->rsc['g2'] = Guide::create(['code' => 'G02', 'full_name' => 'Nadia Flores', 'languages' => ['es', 'fr'], 'license_number' => 'GT-2', 'license_expires_on' => now()->addYear()->toDateString()]);
            $this->rsc['c1'] = Driver::create(['code' => 'C01', 'full_name' => 'Raúl Mamani', 'license_number' => 'Q-1', 'license_class' => 'A-IIb', 'license_expires_on' => now()->addYear()->toDateString()]);
            $this->rsc['v1'] = Vehicle::create(['code' => 'V01', 'plate' => 'CUS-123', 'vehicle_type' => 'Sprinter', 'capacity' => 15, 'soat_expires_on' => now()->addYear()->toDateString()]);
            $this->rsc['v2'] = Vehicle::create(['code' => 'V02', 'plate' => 'CUS-002', 'vehicle_type' => 'Auto', 'capacity' => 1, 'soat_expires_on' => now()->addYear()->toDateString()]);
            $providers = app(ProviderService::class);
            $this->hotel = $providers->create(['name' => 'Hotel Inti Wasi', 'type' => 'Hotel', 'ruc' => '20100000001', 'contact_name' => 'Recepción', 'currency' => 'USD',
                'email' => 'reservas@intiwasi.test', 'contract_start' => now()->subYear()->toDateString(), 'contract_end' => now()->addYear()->toDateString()], $this->users['admin']);
            $providers->activate($this->hotel, $this->users['admin']);
        });
        $this->addUser('guide', 'guide', ['guide_id' => $this->rsc['g1']->id]);
        $this->addUser('guide2', 'guide', ['guide_id' => $this->rsc['g2']->id]);
        $this->addUser('driver', 'driver', ['driver_id' => $this->rsc['c1']->id]);
        $this->addUser('pax', 'passenger', ['reservation_id' => $this->res['id']]);
        $this->addUser('sales', 'sales');
    }

    public function addUser(string $key, string $role, array $attributes = []): User
    {
        $this->users[$key] = $this->makeUser($this->tenant, $role, $attributes + ['code' => strtoupper($key), 'name' => ucfirst($key)]);
        $this->tokens[$key] = $this->tokenFor($this->users[$key]);

        return $this->users[$key];
    }

    /** Orden creada por Operaciones para la reserva del escenario (o la indicada). */
    public function makeOrder(?string $reservationId = null, string $who = 'ops'): array
    {
        return $this->oPost($who, 'orders', ['reservation_id' => $reservationId ?? $this->res['id'], 'coordinator_id' => $this->users['ops']->id], $this->idem())
            ->assertCreated()->json('data');
    }

    /** Confirma con el proveedor todos los servicios activos de la reserva. */
    public function confirmSuppliers(?string $reservationId = null): void
    {
        foreach ($this->admin()->table('booking_services')->where('reservation_id', $reservationId ?? $this->res['id'])->whereIn('status', ['pendiente', 'solicitado'])->pluck('id') as $id) {
            $this->oPost('ops', "reservation-services/{$id}/supplier-confirmation", ['confirmation_code' => 'CF-'.substr($id, -4)])->assertOk();
        }
    }

    public function assignmentOf(string $orderCode, string $kind = 'tour'): object
    {
        return $this->admin()->table('ops_assignments as a')->join('ops_orders as o', 'o.id', '=', 'a.order_id')
            ->join('booking_services as s', 's.id', '=', 'a.reservation_service_id')
            ->where('o.code', $orderCode)->where('s.service_kind', $kind)->select('a.*')->first();
    }

    public function assign(string $assignmentId, array $resources, bool $ack = true, string $who = 'ops'): TestResponse
    {
        return $this->as($who)->withHeaders($this->lockOf('ops_assignments', $assignmentId))
            ->putJson("/api/v1/ops/assignments/{$assignmentId}/resources", $resources + ['ack_warnings' => $ack]);
    }

    public function oGet(string $who, string $uri): TestResponse
    {
        return $this->qGet($who, 'ops/'.ltrim($uri, '/'));
    }

    public function oPost(string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->qPost($who, 'ops/'.ltrim($uri, '/'), $data, $headers);
    }

    public function oSend(string $method, string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->as($who)->withHeaders($headers)->json($method, '/api/v1/ops/'.ltrim($uri, '/'), $data);
    }

    public function orderLock(string $code): array
    {
        return ['If-Match' => (string) $this->admin()->table('ops_orders')->where('code', $code)->value('lock_version')];
    }

    /** Acción de la app del operador. */
    public function mobileAction(string $who, string $type, string $assignmentId, array $payload = [], array $extra = []): TestResponse
    {
        return $this->qPost($who, 'mobile/operator/actions', $extra + [
            'client_action_id' => (string) Str::uuid(), 'type' => $type, 'assignment_id' => $assignmentId,
            'occurred_at' => now()->toIso8601String(), 'offline' => false, 'payload' => $payload,
        ]);
    }

    public function pixel(): array
    {
        return ['file_name' => 'foto.png', 'mime_type' => 'image/png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='];
    }
}
