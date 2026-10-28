<?php

use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\QuoteSelection;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\AccommodationHotel;
use App\Models\Catalog\Hold;
use App\Models\Sys\BookingRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->bookingWorld();
});

function engineStatus(object $test, string $date, int $seats = 0): string
{
    return $test->inTenant(fn () => app(BookingEngine::class)->dayStatus($test->circuit->fresh(), CarbonImmutable::parse($date), $seats)->status);
}

function engineQuote(object $test, array $sel): array
{
    return $test->inTenant(fn () => app(BookingEngine::class)->quote($test->circuit->fresh(), QuoteSelection::fromArray($sel))->toArray());
}

function tenantRule(object $test, string $key, bool $enabled, array $params = []): void
{
    $test->as('admin')->patchJson("/api/v1/admin/booking-rules/{$key}", ['enabled' => $enabled] + ($params ? ['params' => $params] : []))->assertOk();
}

// --- §10.1 caso del cliente ---

it('§10.1 devuelve disponible 02–06, cerrado 07–09 y disponible 10–14 oct; sin rango = cerrado', function () {
    foreach (['2026-10-02', '2026-10-06', '2026-10-10', '2026-10-14'] as $d) {
        expect(engineStatus($this, $d))->toBe('disponible');
    }
    foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $d) {
        expect(engineStatus($this, $d))->toBe('cerrado');
    }
    expect(engineStatus($this, '2026-10-15'))->toBe('cerrado')
        ->and(engineStatus($this, '2026-09-28'))->toBe('pasado');
    // Producto sin rangos ni salidas → «Consultar».
    $other = $this->makeProduct(['name' => 'Sin configurar'], 'ACTIVE');
    expect($this->inTenant(fn () => app(BookingEngine::class)->dayStatus($other, '2026-10-05')->status))->toBe('sin_configurar');
});

it('§10.1 genera la leyenda textual del cliente en la API pública', function () {
    $this->pub('GET', "products/{$this->circuit->id}/availability?from=2026-10-01&to=2026-10-31&seats=2")->assertOk()
        ->assertJsonPath('data.legend', 'Disponible del 02 al 06 oct (12 cupos/día) · Ocupado del 07 al 09 oct · Disponible del 10 al 14 oct (12 cupos/día)')
        ->assertJsonPath('data.next_available', '2026-10-02')
        ->assertJsonPath('data.days.1.status', 'disponible')
        ->assertJsonPath('data.days.6.status', 'cerrado')
        ->assertJsonPath('data.days.0.status', 'cerrado');
    // También por correlativo; producto inexistente o borrador → 404; máximo 92 días.
    $this->pub('GET', "products/{$this->circuit->ref}/availability?from=2026-10-01&to=2026-10-03")->assertOk()->assertJsonCount(3, 'data.days');
    $draft = $this->makeProduct(['name' => 'Borrador']);
    $this->pub('GET', "products/{$draft->id}/availability")->assertNotFound();
    $this->pub('GET', "products/{$this->circuit->id}/availability?from=2026-10-01&to=2027-01-31")->assertStatus(422);
});

it('§10.1 permite un rango cerrado sobre uno disponible (excepción) y rechaza dos disponibles o dos cierres solapados', function () {
    $uri = "products/{$this->circuit->ref}/availability-ranges";
    $this->catPost('ops', $uri, ['date_from' => '2026-10-12', 'date_to' => '2026-10-12', 'status' => 'cerrado', 'note' => 'Feriado'])->assertCreated()
        ->assertJsonPath('data.code', 'RG-1104')->assertJsonPath('data.message', 'Rango RG-1104 creado');
    $this->catPost('ops', $uri, ['date_from' => '2026-10-05', 'date_to' => '2026-10-11', 'status' => 'disponible', 'capacity_per_day' => 10])
        ->assertStatus(422)->assertJsonPath('code', 'CAT-AV-01')->assertJsonPath('conflicts.0.code', 'RG-1101');
    $this->catPost('ops', $uri, ['date_from' => '2026-10-08', 'date_to' => '2026-10-10', 'status' => 'cerrado'])
        ->assertStatus(422)->assertJsonPath('code', 'CAT-AV-01');
    expect(engineStatus($this, '2026-10-12'))->toBe('cerrado')->and(engineStatus($this, '2026-10-11'))->toBe('disponible');
    // CAT-AV-02 / CAT-AV-05.
    $this->catPost('ops', $uri, ['date_from' => '2026-11-01', 'date_to' => '2026-11-05', 'status' => 'disponible', 'capacity_per_day' => 501])->assertStatus(422)->assertJsonPath('code', 'CAT-AV-02');
    $this->catPost('ops', $uri, ['date_from' => '2026-09-01', 'date_to' => '2026-09-20', 'status' => 'disponible', 'capacity_per_day' => 5])->assertStatus(422)->assertJsonPath('code', 'CAT-AV-05');
    $this->catPost('ops', $uri, ['date_from' => '2026-11-01', 'date_to' => '2028-12-01', 'status' => 'disponible', 'capacity_per_day' => 5])->assertStatus(422)->assertJsonPath('code', 'CAT-AV-05');
    // Auditoría del producto.
    $actions = collect($this->auditRows('Producto', $this->circuit->ref))->pluck('action');
    expect($actions)->toContain('disponibilidad_rangos', 'rango_crear');
});

it('§10.1 respeta la anticipación, los días de operación y los cupos (salida programada prevalece)', function () {
    $this->inTenant(fn () => $this->circuit->forceFill(['cutoff_hours' => 96])->save());
    expect(engineStatus($this, '2026-10-02'))->toBe('fuera_de_plazo');
    $this->catSend('PUT', 'ops', "products/{$this->circuit->ref}/operation", ['operating_weekdays' => [1, 2, 3, 4, 5], 'cutoff_hours' => 48])->assertOk()
        ->assertJsonPath('data.effective_cutoff_hours', 48);
    expect(engineStatus($this, '2026-10-03'))->toBe('no_opera'); // sábado
    $this->catSend('PUT', 'ops', "products/{$this->circuit->ref}/operation", ['operating_weekdays' => [], 'cutoff_hours' => 48])->assertStatus(422)->assertJsonPath('code', 'CAT-AV-06');
    $this->catSend('PUT', 'ops', "products/{$this->circuit->ref}/operation", ['operating_weekdays' => [1], 'cutoff_hours' => 9000])->assertStatus(422)->assertJsonPath('code', 'CAT-AV-06');

    $this->soldOn('2026-10-13', 11);
    expect(engineStatus($this, '2026-10-13', 2))->toBe('sin_cupo')
        ->and(engineStatus($this, '2026-10-13', 1))->toBe('disponible');
    $this->soldOn('2026-10-13', 1);
    expect(engineStatus($this, '2026-10-13'))->toBe('agotado');

    // CAT-AV-07: una salida programada en la fecha prevalece sobre la capacidad del rango.
    $this->makeDeparture($this->circuit, ['departure_date' => '2026-10-14', 'capacity' => 3]);
    $day = $this->inTenant(fn () => app(BookingEngine::class)->dayStatus($this->circuit->fresh(), '2026-10-14'));
    expect($day->cap)->toBe(3)->and($day->label)->toBe('Últimos 3 cupos')->and($day->departure)->not->toBeNull();
    // Interna: vendidos y rango por día.
    $this->catGet('u03', "products/{$this->circuit->ref}/booking-availability?from=2026-10-13&to=2026-10-14")->assertOk()
        ->assertJsonPath('data.days.0.sold', 12)->assertJsonPath('data.days.0.range', 'RG-1103')->assertJsonPath('data.days.1.cap', 3);
});

it('§10.1 bloquea la sobreventa al confirmar: dos reservas de 7 pax sobre 12 cupos → 201 y 409 AVAIL_CHANGED', function () {
    $sel = ['date' => '2026-10-03', 'pax' => ['adt' => 7]];
    $this->bookOnline($sel)->assertCreated()->assertJsonPath('data.status', 'pendiente_pago');
    $this->bookOnline($sel)->assertStatus(409)->assertJsonPath('code', 'AVAIL_CHANGED')->assertJsonPath('next_available', '2026-10-04');
    expect(engineStatus($this, '2026-10-03', 6))->toBe('sin_cupo');
});

// --- §10.2 pasajeros, precio, pago y reglas ---

it('§10.2 2 ADT + 1 CHD (5) + 1 INF (2): tarifa Niño prevalece sobre el 73 % y el infante no paga', function () {
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 2, 'chd' => 1, 'inf' => 1], 'ages' => [35, 33, 5, 2]]);
    expect($q['ok'])->toBeTrue()->and($q['currency'])->toBe('USD');
    $base = collect($q['lines'])->where('kind', 'base')->keyBy('key');
    expect($base['base:ADT']['qty'])->toBe(2)->and($base['base:ADT']['unit'])->toBe('1490.00')
        ->and($base['base:CHD']['unit'])->toBe('1090.00')->and($base['base:INF']['unit'])->toBe('0.00')
        ->and($q['total'])->toBe('4070.00');
    // Clasificador de edad y simulador interno.
    $this->catPost('u03', "products/{$this->circuit->ref}/pax-rules/simulate", ['adt' => 1, 'chd' => 3, 'inf' => 2, 'ages' => [40, 7]])->assertOk()
        ->assertJsonPath('data.validation.ok', false)->assertJsonCount(2, 'data.validation.errors')->assertJsonPath('data.validation.ages.1.type', 'CHD');
});

it('§10.2 1 ADT + 3 CHD + 2 INF: dos errores (máx. 2 niños y 1 infante por adulto); sin R-CHILD-PER-ADULT no hay máximo y el niño paga 100 % o tarifa Niño', function () {
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 1, 'chd' => 3, 'inf' => 2]]);
    expect($q['ok'])->toBeFalse()->and(collect($q['warnings'])->where('blocking', true)->pluck('code')->all())->toBe(['R-CHILD-PER-ADULT', 'R-INFANT-FREE']);

    tenantRule($this, 'R-CHILD-PER-ADULT', false);
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 1, 'chd' => 3]]);
    expect($q['ok'])->toBeTrue()->and(collect($q['lines'])->firstWhere('key', 'base:CHD')['unit'])->toBe('1090.00');
    // Sin tarifa Niño: 100 % del adulto.
    $this->inTenant(fn () => DB::table('catalog_rates')->where('product_id', $this->circuit->id)->where('segment', 'Niño')->update(['status' => 'inactiva']));
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 1, 'chd' => 1]]);
    expect(collect($q['lines'])->firstWhere('key', 'base:CHD')['unit'])->toBe('1490.00');
});

it('§10.2 override R-ADDONS=false: quote ignora adicionales y la configuración marca la sección desactivada solo para el producto', function () {
    $addon = $this->catGet('manager', "products/{$this->circuit->ref}/addons")->assertOk()->json('data.0');
    $sel = ['date' => '2026-10-04', 'pax' => ['adt' => 2], 'addons' => [['id' => $addon['id'], 'qty' => 2]]];
    expect(engineQuote($this, $sel)['total'])->toBe('3030.00');
    $this->catSend('PUT', 'manager', "products/{$this->circuit->ref}/rule-overrides/R-ADDONS", ['enabled' => false])->assertOk()->assertJsonPath('data.state', 'off_product');
    expect(engineQuote($this, $sel)['total'])->toBe('2980.00');
    $this->catGet('u03', "products/{$this->circuit->ref}/booking-config")->assertOk()->assertJsonPath('data.sections.adicionales.state', 'off_product')
        ->assertJsonPath('data.addons.0.name', 'Museo Larco'); // la configuración no se borra
    $this->catSend('DELETE', 'manager', "products/{$this->circuit->ref}/rule-overrides/R-ADDONS")->assertOk()->assertJsonPath('data.state', 'on');
    expect(collect($this->auditRows('Producto', $this->circuit->ref))->pluck('action'))->toContain('regla_producto', 'regla_restablecer');
});

it('§10.2 CAT-RL-01/02/03: override de regla solo-tenant → 422; activar una regla apagada en el tenant no la enciende; parámetros validados', function () {
    $this->catSend('PUT', 'admin', "products/{$this->circuit->ref}/rule-overrides/R-HOLD", ['enabled' => false])->assertStatus(422)->assertJsonPath('code', 'CAT-RL-01');
    tenantRule($this, 'R-LODGING', false);
    $this->catSend('PUT', 'admin', "products/{$this->circuit->ref}/rule-overrides/R-LODGING", ['enabled' => true])->assertOk()
        ->assertJsonPath('data.effective', false)->assertJsonPath('meta.warnings.0', fn ($w) => str_starts_with($w, 'CAT-RL-02'));
    expect($this->inTenant(fn () => app(BookingEngine::class)->rules->on('R-LODGING', $this->circuit)))->toBeFalse();
    $this->catGet('admin', "products/{$this->circuit->ref}/booking-config")->assertJsonPath('data.sections.alojamiento.state', 'off_tenant');
    $this->catSend('PUT', 'admin', "products/{$this->circuit->ref}/rule-overrides/R-DEPOSIT", ['params' => ['pct' => 120]])->assertStatus(422)->assertJsonPath('code', 'CAT-RL-03');
    $this->catSend('PUT', 'admin', "products/{$this->circuit->ref}/rule-overrides/R-DEPOSIT", ['params' => ['foo' => 1]])->assertStatus(422)->assertJsonPath('code', 'CAT-RL-03');
    // Valores iguales al tenant no se guardan.
    $this->catSend('PUT', 'admin', "products/{$this->circuit->ref}/rule-overrides/R-DEPOSIT", ['params' => ['pct' => 30, 'balanceDueDays' => 20]])->assertOk()
        ->assertJsonPath('data.override.params', ['balanceDueDays' => 20]);
});

it('§10.2 pax rules con infant_max_age=4 y child_min_age=4 → 422 CAT-PX-01; brecha de edades → advertencia; DELETE vuelve a los defaults', function () {
    $uri = "products/{$this->circuit->ref}/pax-rules";
    $this->catSend('PUT', 'manager', $uri, ['infant_max_age' => 4, 'child_min_age' => 4])->assertStatus(422)->assertJsonPath('code', 'CAT-PX-01');
    $this->catSend('PUT', 'manager', $uri, ['child_price_pct' => 120])->assertStatus(422)->assertJsonPath('code', 'CAT-PX-02');
    $this->catSend('PUT', 'manager', $uri, ['child_min_age' => 4])->assertOk()->assertJsonPath('meta.warnings.0', 'Edades 3–3 sin banda: el checkout las rechazará.');
    expect($this->inTenant(fn () => app(BookingEngine::class)->ageType($this->circuit->fresh(), 3)))->toBe('NA');
    $this->catSend('DELETE', 'manager', $uri)->assertOk()->assertJsonPath('data.effective.source', 'tenant')->assertJsonPath('data.effective.child_price_pct', '75');
    expect(collect($this->auditRows('Producto', $this->circuit->ref))->where('action', 'reglas_pasajeros')->last()->after_value)->toBe('hereda tenant');
});

it('§10.2 alojamiento: doble más económica en 4 tramos con 2 ADT → rooms 4, nights 9, US$ 585; 3 ADT en una doble → advertencia de capacidad', function () {
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 2], 'lodging' => $this->cheapestLodging()]);
    expect($q['rooms'])->toBe(4)->and($q['nights'])->toBe(9)->and($q['lodging_total'])->toBe('585.00')->and($q['total'])->toBe('3565.00')->and($q['ok'])->toBeTrue();
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 3], 'lodging' => $this->cheapestLodging(1, ['Lima'])]);
    expect($q['ok'])->toBeFalse()->and(collect($q['warnings'])->firstWhere('code', 'R-LODGING-CAPACITY')['blocking'])->toBeTrue();
    // Quitar un tramo en la vista previa (R-PREVIEW-REMOVE) recalcula noches y habitaciones.
    $lima = "lodging:{$this->legs['Lima']->id}:Doble";
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 2], 'lodging' => $this->cheapestLodging(), 'removed' => [$lima]]);
    expect($q['nights'])->toBe(7)->and($q['rooms'])->toBe(3)->and($q['lodging_total'])->toBe('445.00');
    // CAT-LG-02: 9 noches = 10 días − 1, sin advertencia; CAT-LG-01 hotel sin habitaciones.
    $cfg = $this->catGet('u03', "products/{$this->circuit->ref}/booking-config")->assertOk();
    expect($cfg->json('data.accommodation.summary'))->toBe('4 ciudad(es) · 9 noche(s)')->and($cfg->json('data.accommodation.warnings'))->toBe([]);
    $this->catPost('manager', "products/{$this->circuit->ref}/accommodation/legs/{$this->legs['Lima']->id}/hotels", ['name' => 'Sin cuartos', 'category' => 'Hostal', 'room_types' => []])
        ->assertStatus(422)->assertJsonPath('code', 'CAT-LG-01');
});

it('§10.2 pago: adelanto 30 % con salida 2026-10-10 y saldo a 30 días → vence hoy con advertencia; sin R-DEPOSIT no hay adelanto; pagar después y recargo por tarjeta', function () {
    $q = engineQuote($this, ['date' => '2026-10-10', 'pax' => ['adt' => 1], 'payment_mode' => 'adelanto']);
    expect($q['payment_mode'])->toBe('adelanto')->and($q['deposit'])->toBe('447.00')->and($q['balance'])->toBe('1043.00')
        ->and($q['balance_due'])->toBe('2026-09-29')->and(collect($q['warnings'])->pluck('code')->all())->toContain('CAT-PY-02');
    tenantRule($this, 'R-DEPOSIT', false);
    expect(engineQuote($this, ['date' => '2026-10-10', 'pax' => ['adt' => 1]])['modes'])->toBe(['total']);
    tenantRule($this, 'R-PAY-LATER', true);
    tenantRule($this, 'R-PAY-FEE', true);
    $q = engineQuote($this, ['date' => '2026-10-10', 'pax' => ['adt' => 1], 'payment_mode' => 'pagar_despues', 'payment_method' => 'tarjeta']);
    expect($q['modes'])->toBe(['total', 'pagar_despues'])->and($q['deposit'])->toBe('0.00')->and($q['hold_hours'])->toBe(24)->and($q['total'])->toBe('1542.15');
    // Política del producto: CAT-PY-01 y el checkbox de pagar después escribe el override.
    $this->catSend('PUT', 'manager', "products/{$this->circuit->ref}/payment-policy", ['modes' => []])->assertStatus(422)->assertJsonPath('code', 'CAT-PY-01');
    $this->catSend('PUT', 'manager', "products/{$this->circuit->ref}/payment-policy", ['modes' => ['total', 'adelanto'], 'deposit_pct' => 40, 'balance_due_days' => 15, 'pay_later_enabled' => false])
        ->assertOk()->assertJsonPath('data.pay_later_enabled', false)->assertJsonPath('data.pay_later_state', 'off_product');
});

it('§10.2 ajustes sobre el subtotal: descuento por grupo, compra anticipada y último minuto no se acumulan en cascada', function () {
    tenantRule($this, 'R-GROUP-DISC', true, ['fromPax' => 4, 'pct' => 10]);
    tenantRule($this, 'R-LAST-MINUTE', true, ['days' => 7, 'pct' => 5]);
    $q = engineQuote($this, ['date' => '2026-10-04', 'pax' => ['adt' => 4]]);
    expect(collect($q['adjustments'])->pluck('amount', 'key')->all())->toBe(['R-GROUP-DISC' => '-596.00', 'R-LAST-MINUTE' => '298.00'])
        ->and($q['subtotal'])->toBe('5960.00')->and($q['total'])->toBe('5662.00');
});

it('§10.2 aislamiento: el tenant B no ve rangos ni overrides de A (404)', function () {
    $tenantA = $this->tenant;
    $range = $this->inTenant(fn () => $this->circuit->availabilityRanges()->first());
    $b = $this->makeTenant('beta');
    $userB = $this->makeUser($b, 'tenant_admin', ['code' => 'B01', 'name' => 'Beta']);
    $tokenB = $this->tokenFor($userB);
    $this->api($tokenB)->getJson("/api/v1/catalog/products/{$this->circuit->id}/booking-config")->assertNotFound();
    $this->api($tokenB)->patchJson("/api/v1/catalog/products/{$this->circuit->id}/availability-ranges/{$range->id}", ['status' => 'cerrado'])->assertNotFound();
    $this->api($tokenB)->getJson("/api/v1/catalog/products/{$this->circuit->id}/rule-overrides")->assertNotFound();
    $this->tenant = $b;
    $this->pub('GET', "products/{$this->circuit->id}/availability")->assertNotFound();
    $this->tenant = $tenantA;
    // El centro de reglas de B tiene sus propias 32 reglas (spec 17 suma R-OWN-REVIEWS).
    $this->api($tokenB)->getJson('/api/v1/admin/booking-rules')->assertOk()->assertJsonCount(32, 'data');
});

it('§10.2 RBAC: Ventas 403 en toda escritura; Operaciones 403 en pasajeros/adicionales/alojamiento/pago/reglas y 2xx en rangos/recojo', function () {
    $ref = $this->circuit->ref;
    $leg = $this->legs['Lima']->id;
    $writes = [
        ['POST', "products/{$ref}/availability-ranges", ['date_from' => '2026-11-01', 'date_to' => '2026-11-03', 'status' => 'disponible', 'capacity_per_day' => 8]],
        ['PUT', "products/{$ref}/pax-rules", ['min_adults' => 2]],
        ['POST', "products/{$ref}/addons", ['name' => 'Cena show', 'category' => 'Alimentación', 'price' => 40, 'currency' => 'USD', 'charge_per' => 'pax', 'applies_to' => ['ADT']]],
        ['PUT', "products/{$ref}/accommodation", ['enabled' => true]],
        ['POST', "products/{$ref}/accommodation/legs/{$leg}/hotels", ['name' => 'Hotel Nuevo Lima', 'category' => '4 estrellas', 'room_types' => [['room_type' => 'Doble', 'capacity' => 2, 'price_per_night' => 95]]]],
        ['PUT', "products/{$ref}/payment-policy", ['modes' => ['total']]],
        ['PUT', "products/{$ref}/pickup", ['required' => false, 'points' => ['Plaza de Armas']]],
        ['PUT', "products/{$ref}/rule-overrides/R-ADDONS", ['enabled' => false]],
        ['PUT', "products/{$ref}/operation", ['operating_weekdays' => [0, 1, 2, 3, 4, 5, 6]]],
    ];
    foreach ($writes as [$method, $uri, $body]) {
        $this->catSend($method, 'u03', $uri, $body)->assertForbidden();
    }
    $opsAllowed = ["products/{$ref}/availability-ranges", "products/{$ref}/pickup", "products/{$ref}/operation"];
    foreach ($writes as [$method, $uri, $body]) {
        $res = $this->catSend($method, 'ops', $uri, $body);
        in_array($uri, $opsAllowed, true) ? expect($res->status())->toBeLessThan(300) : $res->assertForbidden();
    }
    // Manager edita todo menos el centro de reglas del tenant.
    $this->as('manager')->getJson('/api/v1/admin/booking-rules')->assertForbidden();
    $this->catGet('manager', "products/{$ref}/booking-config")->assertOk()->assertJsonPath('data.can.rules_center', false)->assertJsonPath('data.can.rates', true);
    $this->catGet('ops', "products/{$ref}/booking-config")->assertOk()->assertJsonPath('data.can.availability', true)->assertJsonPath('data.can.update', false)->assertJsonPath('data.can.pickup', true);
});

it('§10.2 idempotencia: reintento de POST /public/bookings con la misma Idempotency-Key devuelve la misma reserva; snapshot y hold guardados', function () {
    $sel = ['date' => '2026-10-05', 'pax' => ['adt' => 2, 'chd' => 1], 'lodging' => $this->cheapestLodging(2), 'payment_mode' => 'adelanto', 'currency' => 'USD'];
    $token = $this->pub('POST', 'quote', $sel + ['product_id' => $this->circuit->id])->assertOk()->assertJsonPath('data.ok', true)->json('data.quote_token');
    $body = ['quote_token' => $token, 'contact' => $this->contact(), 'pickup' => ['point_id' => $this->inTenant(fn () => $this->circuit->pickupPoints()->first()->id)], 'comments' => 'Vegetariano'];
    $first = $this->pub('POST', 'bookings', $body, ['Idempotency-Key' => 'bk-1'])->assertCreated();
    $again = $this->pub('POST', 'bookings', $body, ['Idempotency-Key' => 'bk-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($again->json('data.reservation'))->toBe($first->json('data.reservation'));
    $code = $first->json('data.reservation');
    $r = $this->inTenant(fn () => Reservation::where('code', $code)->first());
    expect($r->booking['payment']['mode'])->toBe('adelanto')->and($r->booking['lodging'])->toHaveCount(4)->and($r->booking['comments'])->toBe('Vegetariano')
        ->and($r->booking['rulesApplied'])->toContain('R-LODGING')->and((string) $r->total_amount)->toBe($first->json('data.total'));
    $hold = $this->inTenant(fn () => Hold::where('reference_id', $r->id)->first());
    expect($hold->status)->toBe('hold')->and($hold->quantity)->toBe(3);
    expect($this->inTenant(fn () => Reservation::where('booking->contact->email', 'lucia@correo.test')->count()))->toBe(1);
    // Sin Idempotency-Key → 400; recojo obligatorio.
    $this->pub('POST', 'bookings', $body)->assertStatus(400);
    $this->pub('POST', 'bookings', ['pickup' => []] + $body, $this->idem())->assertStatus(422);
    // Token alterado → 422.
    $this->pub('POST', 'bookings', ['quote_token' => $token.'x'] + $body, $this->idem())->assertStatus(422)->assertJsonPath('code', 'QUOTE_TOKEN_INVALID');
});

it('§10.2 QUOTE_CHANGED si el precio cambió desde la cotización; el hold vencido de una reserva impaga la anula y libera cupos', function () {
    $sel = ['date' => '2026-10-05', 'pax' => ['adt' => 2]];
    $token = $this->pub('POST', 'quote', $sel + ['product_id' => $this->circuit->id])->json('data.quote_token');
    $this->inTenant(fn () => DB::table('catalog_rates')->where('product_id', $this->circuit->id)->where('segment', 'Adulto')->update(['price' => 1590]));
    $this->pub('POST', 'bookings', ['quote_token' => $token, 'contact' => $this->contact(), 'pickup' => ['point_id' => $this->inTenant(fn () => $this->circuit->pickupPoints()->first()->id)]], $this->idem())
        ->assertStatus(409)->assertJsonPath('code', 'QUOTE_CHANGED')->assertJsonPath('quote.total', '3180.00');

    $code = $this->bookOnline($sel)->assertCreated()->json('data.reservation');
    expect(engineStatus($this, '2026-10-05'))->toBe('disponible');
    $left = fn () => $this->inTenant(fn () => app(BookingEngine::class)->dayStatus($this->circuit->fresh(), '2026-10-05')->left);
    expect($left())->toBe(10);
    $this->travel(31)->minutes();
    $this->artisan('booking:run', ['job' => 'expire-holds', '--tenant' => $this->tenant->id])->assertSuccessful();
    expect($this->inTenant(fn () => Reservation::where('code', $code)->value('status')))->toBe('cancelada')->and($left())->toBe(12);
});

it('§10.2 caché: tras crear un rango cerrado, GET availability refleja el cambio sin esperar el TTL; ETag/If-None-Match', function () {
    $uri = "products/{$this->circuit->id}/availability?from=2026-10-01&to=2026-10-31";
    $first = $this->pub('GET', $uri)->assertOk()->assertJsonPath('data.days.3.status', 'disponible');
    $etag = $first->headers->get('ETag');
    $this->pub('GET', $uri, [], ['If-None-Match' => $etag])->assertStatus(304);
    $this->catPost('ops', "products/{$this->circuit->ref}/availability-ranges", ['date_from' => '2026-10-04', 'date_to' => '2026-10-04', 'status' => 'cerrado', 'note' => 'Paro'])->assertCreated();
    $second = $this->pub('GET', $uri)->assertOk()->assertJsonPath('data.days.3.status', 'cerrado')->assertJsonPath('data.days.3.note', 'Paro');
    expect($second->headers->get('ETag'))->not->toBe($etag);
    // Una regla del tenant también invalida.
    tenantRule($this, 'R-AVAIL-RANGES', false);
    $this->pub('GET', $uri)->assertOk()->assertJsonPath('data.days.3.status', 'disponible')->assertJsonPath('data.legend', '');
});

it('§6.2 cierre rápido con ventas exige confirmación (409 CAT-AV-04), avisa a Operaciones y no anula reservas; CAT-AV-03 capacidad bajo lo vendido', function () {
    $this->soldOn('2026-10-11', 4);
    $uri = "products/{$this->circuit->ref}/availability-ranges/close-dates";
    $this->catPost('ops', $uri, ['dates' => ['2026-10-11', '2026-10-12', '2026-10-14'], 'status' => 'cerrado'])->assertStatus(409)
        ->assertJsonPath('code', 'CAT-AV-04')->assertJsonPath('affected.0.date', '2026-10-11');
    $this->catPost('ops', $uri.'?confirm_sales=1', ['dates' => ['2026-10-11', '2026-10-12', '2026-10-14'], 'status' => 'cerrado', 'note' => 'Huelga'])->assertCreated()
        ->assertJsonPath('data.message', '3 fecha(s) cerradas en 2 rango(s)');
    expect($this->admin()->table('notif_messages')->where('event', 'disponibilidad.cerrada_con_ventas')->count())->toBeGreaterThan(0);
    $range = $this->inTenant(fn () => $this->circuit->availabilityRanges()->where('code', 'RG-1103')->first());
    $this->catSend('PATCH', 'ops', "products/{$this->circuit->ref}/availability-ranges/{$range->id}", ['capacity_per_day' => 3])->assertStatus(422)->assertJsonPath('code', 'CAT-AV-03');
    $this->catSend('PATCH', 'ops', "products/{$this->circuit->ref}/availability-ranges/{$range->id}", ['capacity_per_day' => 10, 'lock_version' => $range->lock_version])->assertOk()
        ->assertJsonPath('data.capacity_per_day', 10);
});

it('§6.2 centro de reglas: lista 32 reglas, edita parámetros y agrega una regla propia que el motor no evalúa sin handler', function () {
    $this->as('admin')->getJson('/api/v1/admin/booking-rules')->assertOk()->assertJsonCount(32, 'data')->assertJsonPath('data.0.key', 'R-AVAIL-RANGES');
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-HOLD', ['params' => ['minutes' => 2000]])->assertStatus(422)->assertJsonPath('code', 'CAT-RL-03');
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-HOLD', ['params' => ['minutes' => 20]])->assertOk()->assertJsonPath('data.params.minutes', 20);
    $this->as('admin')->postJson('/api/v1/admin/booking-rules', ['rule_key' => 'R-PET-FRIENDLY', 'group_name' => 'Checkout', 'name' => 'Mascotas', 'description' => 'Permite mascotas', 'enabled' => true,
        'params' => 'maxPets=1, feeUsd=15'])->assertCreated()->assertJsonPath('data.is_system', false)->assertJsonPath('data.evaluated', false)->assertJsonPath('data.params.feeUsd', 15)
        // Spec 17 CR-04: las personalizadas se crean inactivas aunque se pida `enabled`.
        ->assertJsonPath('data.enabled', false);
    expect($this->inTenant(fn () => app(BookingEngine::class)->rules->on('R-PET-FRIENDLY')))->toBeFalse()
        ->and($this->inTenant(fn () => BookingRule::count()))->toBe(33);
    expect($this->auditRows('Regla de reserva', 'R-HOLD'))->toHaveCount(1);
});

it('§2 pago total informa 100 % y el formulario de hotel ignora las filas sin precio', function () {
    expect(engineQuote($this, ['date' => '2026-10-03', 'pax' => ['adt' => 2], 'payment_mode' => 'total'])['deposit_pct'])->toBe('100');
    $leg = $this->legs['Lima'];
    $this->as('admin')->postJson("/api/v1/catalog/products/{$this->circuit->ref}/accommodation/legs/{$leg->id}/hotels", ['name' => 'Hotel Barranco', 'category' => '4 estrellas',
        'room_types' => [['room_type' => 'Simple', 'capacity' => 1, 'price_per_night' => null], ['room_type' => 'Doble', 'capacity' => 2, 'price_per_night' => 95]]])
        ->assertCreated();
    $types = $this->inTenant(fn () => AccommodationHotel::where('name', 'Hotel Barranco')->firstOrFail()->roomTypes->pluck('room_type')->all());
    expect($types)->toBe(['Doble']);
});
