<?php

namespace Database\Seeders;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\SupplierService;
use App\Models\Crm\Client;
use App\Models\Finance\Payable;
use App\Models\Iam\User;
use App\Models\Supplier\RateRequest;
use App\Models\Supplier\SupplierInvoice;
use App\Services\Bookings\ReservationService;
use App\Services\Bookings\SupplierFlow;
use App\Services\Crm\CrmClock;
use App\Services\Finance\PayableService;
use App\Services\Ops\OrderService;
use App\Services\SequenceService;
use App\Services\Supplier\SupplierOperations;
use App\Services\Supplier\SupplierRequests;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo de la extranet (spec 06) para U17 → PRV-001 (Hotel Inti Wasi): solicitudes por responder (un grupo y un titular),
 * una alternativa en revisión, la estadía confirmada de John Miller con voucher y rooming por acusar, cuentas por pagar
 * en distintos estados, una incidencia reportada desde la extranet y el historial de solicitudes de tarifa.
 */
class SupplierDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $this->u = User::whereIn('code', ['U01', 'U03', 'U05', 'U07', 'U17'])->get()->keyBy('code')->all();
        $today = app(CrmClock::class)->today();
        $day = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $hotel = Provider::findOrFail(DemoSeeder::linkId('PRV-001'));
        $room = SupplierService::where('provider_id', $hotel->id)->where('name', 'like', 'Habitación doble%')->firstOrFail();
        $flow = app(SupplierFlow::class);

        // Solicitudes enviadas al hotel por Operaciones desde la orden de cada reserva.
        $group = $this->reservation('CLI-0004', 'Promoción Colegio San Agustín — Cusco', $day(4), 8, 'Carlos Medina');
        $groupRoom = $this->addService($group, $room, $day(4), 8, 4 * 2 * 95.0, 4 * 2 * 140.0);
        $couple = $this->reservation('CLI-0001', 'Aniversario en Cusco — Ana Gutiérrez', $day(2), 2, 'Ana Gutiérrez');
        $coupleRoom = $this->addService($couple, $room, $day(1), 2, 2 * 95.0, 2 * 140.0);
        $family = $this->reservation('CLI-0005', 'Cusco en familia — María Fernández', $day(20), 3, 'María Fernández');
        $familyRoom = $this->addService($family, $room, $day(20), 3, 2 * 110.0, 2 * 160.0);
        $this->as('U05', function () use ($flow, $group, $groupRoom, $couple, $coupleRoom, $family, $familyRoom) {
            foreach ([$group, $couple, $family] as $reservation) {
                app(OrderService::class)->create($reservation, $this->u['U05']->id, $this->u['U05']);
            }
            $flow->request($group, $groupRoom, $this->u['U05']);
            $flow->request($couple, $coupleRoom, $this->u['U05']);
            $flow->request($family, $familyRoom, $this->u['U05']);
        });
        // La familia viaja con un menor: el hotel propone una triple (alternativa en la bandeja de Operaciones).
        $this->as('U17', fn () => app(SupplierRequests::class)->propose($hotel, $familyRoom->fresh(), [
            'kind' => 'Otro tipo de habitación', 'net_cost' => 230, 'hold_until' => $day(7),
            'note' => 'No quedan dobles para esas noches; ofrecemos una triple superior por USD 115 la noche.',
        ], $this->u['U17']));

        // Cuentas por pagar de todos los servicios confirmados (las estadías de la demo se confirmaron sin pasar por la extranet).
        $payables = app(PayableService::class);
        foreach (BookedService::where('status', BookedService::CONFIRMED)->whereNotNull('provider_id')->orderBy('service_date')->get() as $service) {
            $this->as('U05', fn () => $payables->ensureForService($service, $this->u['U05']));
        }
        // Historial con el hotel: una liquidación pagada y otra programada con factura validada.
        $sequences = app(SequenceService::class);
        $paid = Payable::create(['code' => $sequences->next('payable'), 'provider_id' => $hotel->id, 'concept' => 'Liquidación de estadías de septiembre (6 noches)',
            'amount' => 612, 'paid_amount' => 612, 'currency' => 'USD', 'due_date' => $day(-26), 'status' => 'pagada', 'paid_at' => $today->copy()->subDays(27)]);
        $scheduled = Payable::create(['code' => $sequences->next('payable'), 'provider_id' => $hotel->id, 'concept' => 'Liquidación de estadías de octubre (4 noches)',
            'amount' => 380, 'currency' => 'USD', 'due_date' => $day(6), 'status' => 'aprobada']);
        $this->invoice($paid, 'F001-000941', $today->copy()->subDays(40)->toDateString(), 'validada');
        $this->invoice($scheduled, 'F001-000987', $today->copy()->subDays(5)->toDateString(), 'validada');

        // Incidencia reportada por el hotel sobre la estadía del grupo (la orden de John Miller queda lista para su cierre).
        $this->as('U17', fn () => app(SupplierOperations::class)->report($hotel, ['reservation_service_id' => $groupRoom->id, 'type' => 'Capacidad', 'severity' => 'baja',
            'title' => 'Habitaciones contiguas para el grupo', 'description' => 'El colegio pide las 4 habitaciones en el mismo piso. Podemos bloquear el tercer piso si Operaciones confirma la hora de llegada.'], $this->u['U17']));

        // Historial de tarifas: una solicitud anterior rechazada.
        RateRequest::create(['code' => $sequences->next('rate_request'), 'provider_id' => $hotel->id, 'service_id' => $room->id, 'current_cost' => 95, 'proposed_cost' => 120,
            'currency' => 'USD', 'effective_from' => $day(-30), 'reason_type' => 'Cambio de temporada', 'reason_detail' => 'Temporada alta de julio.',
            'status' => 'rechazada', 'requested_by' => $this->u['U17']->id, 'requested_at' => $today->copy()->subDays(75), 'reviewed_by' => $this->u['U01']->id,
            'reviewed_at' => $today->copy()->subDays(70), 'review_note' => 'El tarifario de temporada alta ya está pactado en el contrato vigente.']);
    }

    private function reservation(string $client, string $title, string $date, int $adults, string $lead): Reservation
    {
        $product = Product::where('ref', 'PRD-002')->where('name', 'like', 'City Tour%')->firstOrFail();
        $rate = Rate::where('product_id', $product->id)->where('segment', '<>', 'Niño')->where('channel', 'Directo')->whereNull('season_id')
            ->whereDate('valid_to', '>=', $date)->orderBy('ref')->first();

        // La reserva la registra el asesor responsable del cliente (cartera, CRM-BR).
        $client = Client::where('code', $client)->firstOrFail();
        $owner = User::find($client->owner_id) ?? $this->u['U03'];
        Auth::setUser($owner);

        $reservation = app(ReservationService::class)->createDirect([
            'client_id' => $client->id, 'product_id' => $product->id, 'rate_id' => $rate?->id,
            'date' => $date, 'adt' => $adults, 'title' => $title, 'confirm' => true,
        ], $owner);
        Passenger::where('reservation_id', $reservation->id)->orderBy('seq')->first()?->forceFill(['full_name' => $lead, 'is_lead' => true])->save();

        return $reservation;
    }

    private function addService(Reservation $reservation, SupplierService $service, string $date, int $pax, float $cost, float $price): BookedService
    {
        return BookedService::create(BookedService::kindFor($service->type) + [
            'reservation_id' => $reservation->id, 'code' => app(SequenceService::class)->next('booking_service'), 'catalog_service_id' => $service->id,
            'provider_id' => $service->provider_id, 'name' => $service->name, 'service_date' => $date, 'pax' => $pax, 'status' => BookedService::PENDING,
            'cost_amount' => $cost, 'price_amount' => $price, 'is_required' => true,
        ]);
    }

    private function invoice(Payable $payable, string $number, string $issued, string $status): void
    {
        $path = 'tenants/'.app(TenantContext::class)->id().'/supplier/invoices/'.Str::uuid7().'.pdf';
        $content = "%PDF-1.4\n% Factura {$number} (demo)\n";
        Storage::disk((string) config('turismo.evidence_disk', 'local'))->put($path, $content);
        SupplierInvoice::create(['payable_id' => $payable->id, 'provider_id' => $payable->provider_id, 'number' => $number, 'issue_date' => $issued,
            'amount' => $payable->amount, 'currency' => $payable->currency, 'amount_difference' => 0, 'storage_path' => $path, 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'original_file_name' => "{$number}.pdf", 'status' => $status,
            'uploaded_by' => $this->u['U17']->id, 'uploaded_at' => $issued, 'validated_by' => $this->u['U07']->id, 'validated_at' => $issued]);
    }

    private function as(string $code, callable $fn): mixed
    {
        Auth::setUser($this->u[$code]);

        return $fn();
    }
}
