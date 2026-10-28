<?php

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Experience\ReviewRequest;
use App\Services\Bookings\ReservationService;
use App\Services\Experience\OwnReviewService;
use App\Services\Experience\ReviewRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Spec 18 §10: reseñas propias verificadas (R-OWN-REVIEWS). «Hoy» = 2026-09-29 09:00 Lima (BookingWorld).
beforeEach(function () {
    $this->bookingWorld();
    x18Rule($this, ['enabled' => true]);
});

function x18Rule(object $test, array $patch): void
{
    $test->as('admin')->patchJson('/api/v1/admin/booking-rules/R-OWN-REVIEWS', $patch)->assertOk();
}

/**
 * Viaje del circuito con titular y su cuenta de pasajero: devuelve [reserva, token del pasajero].
 * `$serviceStatus`/`$date` describen el único servicio (producto = circuito).
 */
function x18Trip(object $test, string $status = Reservation::COMPLETED, string $date = '2026-09-20', string $serviceStatus = BookedService::COMPLETED, string $owner = 'u03'): array
{
    return (function () use ($status, $date, $serviceStatus, $owner) {
        $r = $this->soldOn($date, 2);
        $email = 'pax'.random_int(1000, 999999).'@correo.test';
        $client = $this->makeClient($owner, ['name' => 'Thomas Keller', 'country' => 'Alemania', 'doc_type' => 'PASAPORTE', 'doc_number' => 'C'.random_int(1000000, 9999999)]);
        $this->inTenant(function () use ($r, $status, $serviceStatus, $owner, $email, $client) {
            $r->forceFill(['status' => $status, 'client_id' => $client->id, 'owner_id' => $this->users[$owner]->id])->save();
            BookedService::where('reservation_id', $r->id)->update(['status' => $serviceStatus]);
            Passenger::create(['reservation_id' => $r->id, 'seq' => 1, 'full_name' => 'Thomas Keller', 'pax_type' => 'ADT', 'is_lead' => true, 'email' => $email,
                'nationality' => 'Alemania', 'is_placeholder' => false]);
        });
        $token = $this->tokenFor($this->makeUser($this->tenant, 'passenger', ['email' => $email, 'name' => 'Thomas Keller', 'reservation_id' => $r->id, 'client_id' => $client->id]));

        return [$this->inTenant(fn () => $r->fresh()), $token];
    })->call($test);
}

function x18Review(array $overrides = []): array
{
    return $overrides + ['product' => test()->circuit->ref, 'stars' => 5, 'subratings' => ['guia' => 5, 'organizacion' => 4], 'title' => 'Viaje redondo',
        'body' => 'Todo salió según lo previsto, el guía fue muy atento y los hoteles cómodos.', 'lang' => 'es', 'consent_publish' => true];
}

function x18Post(object $test, string $token, array $data, int $status = 201)
{
    return (fn () => $this->api($token))->call($test)->withHeaders($test->idem())->postJson('/api/v1/portal/passenger/reviews', $data)->assertStatus($status);
}

// --- tenant ---

it('§10 aísla reseñas, solicitudes y ajustes por tenant; la salida pública de B no muestra las de A', function () {
    [, $pax] = x18Trip($this);
    x18Rule($this, ['params' => ['moderation' => false]]);
    $code = x18Post($this, $pax, x18Review())->assertJsonPath('data.status', 'publicada')->json('data.code');
    $this->as('admin')->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(1, 'data');
    $this->pub('GET', 'reviews/verified', ['product' => $this->circuit->ref])->assertOk()->assertJsonPath('data.block.count', 1);

    $b = $this->makeTenant('beta');
    $tokenB = $this->tokenFor($this->makeUser($b, 'tenant_admin', ['code' => 'B01', 'name' => 'Beta']));
    $this->api($tokenB)->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(0, 'data');
    $this->api($tokenB)->getJson("/api/v1/reviews/{$code}")->assertNotFound();
    $this->api($tokenB)->getJson('/api/v1/review-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->api($tokenB)->getJson('/api/v1/settings/own-reviews')->assertOk()->assertJsonPath('data.state', 'off_tenant');
    $this->withHeaders(['X-Tenant-Slug' => 'beta'])->getJson('/api/v1/public/reviews/verified?product='.$this->circuit->ref)->assertOk()->assertJsonPath('data.block', null);
});

// --- RVW-01 ---

it('RVW-01: con la regla apagada o el plan Starter no hay formulario (403) ni salida pública; los datos se conservan', function () {
    [, $pax] = x18Trip($this);
    x18Rule($this, ['params' => ['moderation' => false]]);
    x18Post($this, $pax, x18Review());
    $this->pub('GET', 'reviews/verified', ['product' => $this->circuit->ref])->assertOk()->assertJsonPath('data.block.count', 1);

    x18Rule($this, ['enabled' => false]);
    [, $pax2] = x18Trip($this);
    x18Post($this, $pax2, x18Review(), 403)->assertJsonPath('code', 'RULE_DISABLED');
    $this->pub('GET', 'reviews/verified', ['product' => $this->circuit->ref])->assertOk()->assertJsonPath('data.block', null);
    $this->api($pax2)->getJson('/api/v1/portal/passenger/reviews/eligibility')->assertOk()->assertJsonPath('data.enabled', false);
    expect($this->inTenant(fn () => app(OwnReviewService::class)->jsonLd($this->circuit)))->toBe([]);
    $this->as('admin')->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(1, 'data');

    x18Rule($this, ['enabled' => true]);
    DB::connection('pgsql_admin')->table('tenants')->where('id', $this->tenant->id)->update(['plan_id' => 'starter']);
    $this->tenant = $this->tenant->fresh();
    x18Post($this, $pax2, x18Review(), 403)->assertJsonPath('code', 'RULE_NOT_IN_PLAN');
    $this->pub('GET', 'reviews/verified', ['product' => $this->circuit->ref])->assertOk()->assertJsonPath('data.block', null);
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/reviews/export-feed')->assertForbidden()->assertJsonPath('code', 'RULE_NOT_IN_PLAN');
});

// --- RVW-02 / RVW-03 / RVW-04 ---

it('RVW-02: reserva cancelada, servicio futuro o producto ajeno a la reserva son 422; RVW-03 duplicado es 409; RVW-04 valida campos', function () {
    // Cancelada: el portal ya no muestra el viaje (PAX-BR-01); borrador / pendiente de pago: 422.
    [, $cancelled] = x18Trip($this, Reservation::CANCELLED);
    x18Post($this, $cancelled, x18Review(), 404);
    [, $unpaid] = x18Trip($this, 'pendiente_pago', '2026-09-20', BookedService::CONFIRMED);
    x18Post($this, $unpaid, x18Review(), 422)->assertJsonPath('code', 'RVW-02');
    [, $future] = x18Trip($this, Reservation::IN_OPERATION, '2026-10-02', BookedService::CONFIRMED);
    x18Post($this, $future, x18Review(), 422)->assertJsonPath('code', 'RVW-02');
    $this->api($future)->getJson('/api/v1/portal/passenger/reviews/eligibility')->assertOk()->assertJsonPath('data.items.0.status', 'aun_no')->assertJsonPath('data.pending', 0);
    $other = $this->makeProduct(['code' => 'OTRO-TOUR', 'name' => 'Otro tour'], 'ACTIVE');
    [, $pax] = x18Trip($this, Reservation::IN_OPERATION, '2026-09-28', BookedService::CONFIRMED);
    x18Post($this, $pax, x18Review(['product' => $other->ref]), 422)->assertJsonPath('code', 'RVW-02');
    $this->api($pax)->getJson('/api/v1/portal/passenger/reviews/eligibility')->assertOk()->assertJsonPath('data.items.0.status', 'pendiente')->assertJsonPath('data.pending', 1);

    x18Post($this, $pax, x18Review(['stars' => 6]), 422);
    x18Post($this, $pax, x18Review(['body' => 'Muy corto']), 422)->assertJsonPath('errors.body.0', 'La reseña debe tener entre 20 y 2000 caracteres.');
    x18Post($this, $pax, x18Review(['title' => 'Ok']), 422);
    x18Post($this, $pax, x18Review(['subratings' => ['comida' => 5]]), 422);
    x18Post($this, $pax, x18Review(['photos' => [['name' => 'a.txt', 'content_base64' => base64_encode('hola')]]]), 422);
    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $code = x18Post($this, $pax, x18Review(['photos' => [['name' => 'machu.png', 'content_base64' => $png]]]))->assertJsonPath('data.status', 'pendiente')->json('data.code');
    $this->as('admin')->getJson("/api/v1/reviews/{$code}")->assertOk()->assertJsonPath('data.photos.0.name', 'machu.png')->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.author_display', 'Thomas K.');
    x18Post($this, $pax, x18Review(), 409)->assertJsonPath('code', 'RVW-03');
});

// --- §4 máquina de estados ---

it('§4: autopublica según minStarsAutoPublish y moderation (o auto_publish); sin consentimiento queda oculta', function () {
    [, $a] = x18Trip($this);
    x18Post($this, $a, x18Review())->assertJsonPath('data.status', 'pendiente'); // moderation = true por defecto
    x18Rule($this, ['params' => ['moderation' => false]]);
    [, $b] = x18Trip($this);
    $code = x18Post($this, $b, x18Review())->assertJsonPath('data.status', 'publicada')->json('data.code');
    expect(collect($this->as('admin')->getJson("/api/v1/reviews/{$code}")->json('data.log'))->pluck('action')->all())->toBe(['crear', 'autopublicar']);
    [, $c] = x18Trip($this);
    x18Post($this, $c, x18Review(['stars' => 3]))->assertJsonPath('data.status', 'pendiente');
    [, $d] = x18Trip($this);
    x18Post($this, $d, x18Review(['consent_publish' => false]))->assertJsonPath('data.status', 'oculta')->assertJsonPath('data.status_label', 'Solo para la agencia');

    x18Rule($this, ['params' => ['moderation' => true]]);
    $this->as('admin')->patchJson('/api/v1/settings/own-reviews', ['auto_publish' => true])->assertOk();
    [, $e] = x18Trip($this);
    x18Post($this, $e, x18Review(['stars' => 4]))->assertJsonPath('data.status', 'publicada');
    $this->as('admin')->patchJson('/api/v1/admin/booking-rules/R-OWN-REVIEWS', ['params' => ['minStarsAutoPublish' => 6]])->assertStatus(422);
});

// --- RVW-05 / RVW-06 / RVW-07 ---

it('RVW-06: datos personales retienen la reseña (publicar exige confirmar) o se enmascaran; RVW-05 sin consentimiento y RVW-07 sin motivo son 422', function () {
    x18Rule($this, ['params' => ['moderation' => false]]);
    [, $pax] = x18Trip($this);
    $held = x18Post($this, $pax, x18Review(['body' => 'Excelente viaje, escríbanme a thomas.keller@mail.de o al +49 151 2345 6789 si dudan.']))
        ->assertJsonPath('data.status', 'pendiente')->json('data.code');
    $r = $this->as('admin')->getJson("/api/v1/reviews/{$held}")->assertOk()->assertJsonPath('data.flags', ['Dato personal: correo', 'Dato personal: teléfono'])->json('data');
    $this->as('admin')->getJson('/api/v1/reviews/stats')->assertOk()->assertJsonPath('data.pending_flagged', 1);
    $this->as('admin')->withHeaders(['If-Match' => (string) $r['lock_version']])->postJson("/api/v1/reviews/{$held}/publish")->assertStatus(422)->assertJsonPath('code', 'RVW-06');
    $this->as('admin')->postJson("/api/v1/reviews/{$held}/publish", ['confirm_flags' => true])->assertStatus(428);
    $this->as('admin')->withHeaders(['If-Match' => (string) $r['lock_version']])->postJson("/api/v1/reviews/{$held}/publish", ['confirm_flags' => true])->assertOk()
        ->assertJsonPath('data.status', 'publicada');

    $this->as('admin')->patchJson('/api/v1/settings/own-reviews', ['personal_data' => 'enmascarar', 'banned_words' => 'estafa, basura'])->assertOk();
    [, $pax2] = x18Trip($this);
    $masked = x18Post($this, $pax2, x18Review(['body' => 'Muy recomendable, mi DNI 45678912 y correo ana@correo.pe por si acaso.']))->assertJsonPath('data.status', 'publicada')->json('data.code');
    $this->as('admin')->getJson("/api/v1/reviews/{$masked}")->assertJsonPath('data.flags', [])
        ->assertJsonPath('data.body', 'Muy recomendable, mi [dato oculto] y correo [dato oculto] por si acaso.');
    [, $pax3] = x18Trip($this);
    x18Post($this, $pax3, x18Review(['title' => 'Una estafa total']))->assertJsonPath('data.status', 'pendiente');

    [, $pax4] = x18Trip($this);
    $hidden = x18Post($this, $pax4, x18Review(['consent_publish' => false]))->json('data.code');
    $v = $this->as('admin')->getJson("/api/v1/reviews/{$hidden}")->json('data.lock_version');
    $this->as('admin')->withHeaders(['If-Match' => (string) $v])->postJson("/api/v1/reviews/{$hidden}/publish")->assertStatus(422)->assertJsonPath('code', 'RVW-05');
    $v = $this->as('admin')->getJson("/api/v1/reviews/{$masked}")->json('data.lock_version');
    $this->as('admin')->withHeaders(['If-Match' => (string) $v])->postJson("/api/v1/reviews/{$masked}/reject", ['reason' => ''])->assertStatus(422)->assertJsonPath('code', 'RVW-07');
    $this->as('admin')->withHeaders(['If-Match' => (string) $v])->postJson("/api/v1/reviews/{$masked}/hide", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'RVW-07');
    $this->as('admin')->withHeaders(['If-Match' => (string) $v])->postJson("/api/v1/reviews/{$masked}/reject", ['reason' => 'Describe otro producto'])->assertOk()
        ->assertJsonPath('data.status', 'rechazada')->assertJsonPath('data.moderation_reason', 'Describe otro producto');
    expect(collect($this->auditRows('Reseña propia', $masked))->pluck('action')->all())->toBe(['crear', 'rechazar']);
    $this->pub('GET', 'reviews/verified', ['product' => $this->circuit->ref])->assertOk()->assertJsonPath('data.block.count', 1);
});

// --- RVW-11 / §7 ---

it('RVW-11: Ventas ve solo su cartera y no modera (403); Operaciones 403; Manager no cambia los parámetros de la regla', function () {
    x18Rule($this, ['params' => ['moderation' => false]]);
    [, $mine] = x18Trip($this, owner: 'u03');
    [, $theirs] = x18Trip($this, owner: 'u04');
    $own = x18Post($this, $mine, x18Review())->json('data.code');
    $foreign = x18Post($this, $theirs, x18Review())->json('data.code');
    $this->as('u03')->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $own)->assertJsonPath('meta.own_portfolio', true)
        ->assertJsonPath('meta.can_moderate', false);
    $this->as('u03')->getJson("/api/v1/reviews/{$foreign}")->assertForbidden()->assertJsonPath('code', 'OWN_PORTFOLIO');
    $this->as('u03')->withHeaders(['If-Match' => '1'])->postJson("/api/v1/reviews/{$own}/hide", ['reason' => 'Prueba de permisos'])->assertForbidden();
    $this->as('u03')->putJson("/api/v1/reviews/{$own}/reply", ['text' => 'Gracias por su reseña'])->assertForbidden();
    $this->as('u03')->getJson('/api/v1/reviews/stats')->assertOk()->assertJsonPath('data.published', 1);
    $this->as('ops')->getJson('/api/v1/reviews')->assertForbidden();
    $this->as('finance')->getJson('/api/v1/reviews/stats')->assertForbidden();
    $this->as('accounting')->getJson('/api/v1/review-requests')->assertForbidden();

    $this->as('manager')->patchJson('/api/v1/settings/own-reviews', ['params' => ['daysAfter' => 3]])->assertForbidden()->assertJsonPath('code', 'ADMIN_ONLY');
    $this->as('manager')->patchJson('/api/v1/settings/own-reviews', ['show_on_widget' => false])->assertOk()->assertJsonPath('data.settings.show_on_widget', false)
        ->assertJsonPath('data.can_edit_rule', false);
    $this->as('admin')->patchJson('/api/v1/settings/own-reviews', ['params' => ['daysAfter' => 3, 'moderation' => true]])->assertOk()->assertJsonPath('data.params.daysAfter', 3)
        ->assertJsonPath('data.params.moderation', true);
    expect(collect($this->auditRows('Regla de reserva R-OWN-REVIEWS', 'R-OWN-REVIEWS'))->pluck('action')->all())->toBe(['parametros'])
        ->and(collect($this->auditRows('Reseñas propias', 'ajustes'))->pluck('action')->all())->toBe(['ajustes/actualizar']);
    $this->as('admin')->patchJson('/api/v1/settings/own-reviews', ['invite' => ['body' => 'Califique y gane un descuento del 10 %: {enlace}']])->assertStatus(422)->assertJsonPath('code', 'RVW-09');
});

// --- RVW-08 ---

it('RVW-08: la respuesta con teléfono es 422; la válida no cambia el estado y llega a los mensajes del portal del pasajero', function () {
    [, $pax] = x18Trip($this);
    $code = x18Post($this, $pax, x18Review())->json('data.code');
    $v = $this->as('admin')->getJson("/api/v1/reviews/{$code}")->json('data.lock_version');
    $this->as('admin')->withHeaders(['If-Match' => (string) $v])->putJson("/api/v1/reviews/{$code}/reply", ['text' => 'Llámenos al 984 123 456 para coordinar.'])
        ->assertStatus(422)->assertJsonPath('code', 'RVW-08');
    $this->as('admin')->withHeaders(['If-Match' => (string) $v])->putJson("/api/v1/reviews/{$code}/reply", ['text' => 'Gracias, Thomas. Nos alegra que el viaje saliera redondo.'])
        ->assertOk()->assertJsonPath('data.status', 'pendiente')->assertJsonPath('data.reply', 'Gracias, Thomas. Nos alegra que el viaje saliera redondo.');
    $this->api($pax)->getJson('/api/v1/portal/passenger/alerts')->assertOk()->assertJsonPath('meta.messages.0.subject', 'Respuesta a su reseña «Viaje redondo»');
    $this->api($pax)->getJson('/api/v1/portal/passenger/reviews/eligibility')->assertOk()->assertJsonPath('data.reviews.0.status_label', 'En revisión')
        ->assertJsonPath('data.reviews.0.reply', 'Gracias, Thomas. Nos alegra que el viaje saliera redondo.');
    expect(collect($this->as('admin')->getJson("/api/v1/reviews/{$code}")->json('data.log'))->pluck('action')->all())->toBe(['crear', 'responder']);
});

// --- RVW-09 / §8 ---

it('RVW-09: ReservationCompleted programa la invitación daysAfter días después a las 09:00; el job la envía, el enlace es de un solo uso y vence', function () {
    [$r] = x18Trip($this, Reservation::IN_OPERATION, '2026-09-28', BookedService::CONFIRMED);
    $this->inTenant(fn () => app(ReservationService::class)->complete($r->fresh(), $this->users['admin']));
    $req = $this->as('admin')->getJson('/api/v1/review-requests')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($req['status'])->toBe('programada')->and(Carbon::parse($req['scheduled_at'])->setTimezone('America/Lima')->format('Y-m-d H:i'))->toBe('2026-09-29 09:00');

    // Un día más con daysAfter = 3: la siguiente se programa al 3.er día.
    $this->as('admin')->patchJson('/api/v1/settings/own-reviews', ['params' => ['daysAfter' => 3]])->assertOk();
    [$r2] = x18Trip($this, Reservation::COMPLETED, '2026-09-27');
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/review-requests/schedule-pending')->assertOk()->assertJsonPath('data.scheduled', 1);
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/review-requests/schedule-pending')->assertOk()->assertJsonPath('data.scheduled', 0);
    $due = $this->inTenant(fn () => ReviewRequest::where('reservation_id', $r2->id)->first());
    expect($due->scheduled_at->setTimezone('America/Lima')->format('Y-m-d H:i'))->toBe('2026-09-30 09:00');

    // El job a las 09:00 de hoy envía la primera y no la de mañana.
    Carbon::setTestNow(Carbon::parse('2026-09-29 09:05', 'America/Lima'));
    expect($this->inTenant(fn () => app(ReviewRequestService::class)->dispatchDue()))->toMatchArray(['sent' => 1, 'reminded' => 0, 'expired' => 0]);
    $this->as('admin')->postJson("/api/v1/review-requests/{$req['code']}/preview")->assertOk()->assertJsonPath('data.channel', 'email');
    $sent = $this->as('admin')->postJson("/api/v1/review-requests/{$req['code']}/send")->assertOk()->assertJsonPath('data.status', 'enviada')->json('data');
    parse_str((string) parse_url($sent['dev_link'], PHP_URL_QUERY), $q);
    $this->pub('GET', "reviews/requests/{$req['code']}?t=otro")->assertStatus(422)->assertJsonPath('code', 'SRV-TOKEN');
    $this->pub('GET', "reviews/requests/{$req['code']}?t={$q['t']}")->assertOk()->assertJsonPath('data.items.0.status', 'pendiente');
    $this->pub('POST', 'reviews/by-token', x18Review(['request' => $req['code'], 't' => $q['t']]), $this->idem())->assertCreated()->assertJsonPath('data.status', 'pendiente');
    $this->pub('POST', 'reviews/by-token', x18Review(['request' => $req['code'], 't' => $q['t']]), $this->idem())->assertStatus(422)->assertJsonPath('code', 'SRV-TOKEN');
    $this->as('admin')->getJson('/api/v1/review-requests?status=respondida')->assertOk()->assertJsonCount(1, 'data');
    $this->as('admin')->postJson("/api/v1/review-requests/{$req['code']}/cancel")->assertStatus(422)->assertJsonPath('code', 'SRV-STATE');

    // Recordatorio a los 3 días y vencimiento a los 14.
    $this->as('admin')->postJson("/api/v1/review-requests/{$due->code}/send")->assertOk();
    Carbon::setTestNow(Carbon::parse('2026-10-03 10:00', 'America/Lima'));
    expect($this->inTenant(fn () => app(ReviewRequestService::class)->dispatchDue()))->toMatchArray(['reminded' => 1]);
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00', 'America/Lima'));
    expect($this->inTenant(fn () => app(ReviewRequestService::class)->dispatchDue()))->toMatchArray(['expired' => 1]);
    expect(collect($this->auditRows('Solicitud de reseña', $due->code))->pluck('action')->all())->toBe(['programar', 'enviar', 'vencer']);
});

// --- §11 JSON-LD y feed ---

it('§11: el JSON-LD lleva aggregateRating y hasta 5 Review solo con reseñas propias publicadas; exportar el feed queda auditado', function () {
    expect($this->inTenant(fn () => app(OwnReviewService::class)->jsonLd($this->circuit)))->toBe([]);
    x18Rule($this, ['params' => ['moderation' => false]]);
    foreach ([5, 4, 5] as $stars) {
        [, $pax] = x18Trip($this);
        x18Post($this, $pax, x18Review(['stars' => $stars]));
    }
    $ld = $this->inTenant(fn () => app(OwnReviewService::class)->jsonLd($this->circuit));
    expect($ld['aggregateRating'])->toBe(['@type' => 'AggregateRating', 'ratingValue' => 4.7, 'reviewCount' => 3, 'bestRating' => 5, 'worstRating' => 1])
        ->and($ld['review'])->toHaveCount(3)->and($ld['review'][0]['author'])->toBe(['@type' => 'Person', 'name' => 'Thomas K.']);
    $this->pub('GET', 'reviews/verified', ['product' => $this->circuit->ref, 'stars' => 4])->assertOk()->assertJsonPath('data.block.total', 1)
        ->assertJsonPath('data.block.distribution.5', 2);
    $this->as('admin')->withHeaders($this->idem())->postJson('/api/v1/reviews/export-feed')->assertOk()->assertJsonPath('data.last_export.count', 3);
    expect(collect($this->auditRows('Reseñas propias', 'feed'))->pluck('action')->all())->toBe(['feed/exportar_widget']);
    $this->as('admin')->patchJson('/api/v1/settings/own-reviews', ['show_on_site' => false])->assertOk();
    $this->pub('GET', 'reviews/verified', ['product' => $this->circuit->ref])->assertOk()->assertJsonPath('data.block', null);
});
