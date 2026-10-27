<?php

use App\Http\Controllers\Api\Collab\InboundWebhookController;
use App\Services\Collab\InboundProcessor;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->channelWorld();
});

/** Conversación entrante por el procesador real (mismo camino que los webhooks). */
function inbound(object $t, string $channel, array $msg): string
{
    return $t->inTenant(fn () => app(InboundProcessor::class)->process($channel, $msg + ['external_message_id' => 'm-'.bin2hex(random_bytes(6))]))['conversation'];
}

function cnv(string $code): object
{
    return DB::connection('pgsql_admin')->table('collab_conversations')->where('code', $code)->first();
}

function cnvLock(string $code): array
{
    return ['If-Match' => (string) cnv($code)->lock_version];
}

function waPayload(string $id, string $from, string $text, string $name = 'Hans Keller'): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
        'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]];
}

function waSign(array $payload, string $secret = InboundWebhookController::SANDBOX_WHATSAPP_SECRET): array
{
    return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), $secret)];
}

it('§10-1 aislamiento: el tenant B no lista ni abre conversaciones del tenant A', function () {
    $code = inbound($this, 'webchat', ['thread_id' => 's-1', 'name' => 'Lucía', 'email' => 'lucia@correo.test', 'body' => 'Hola']);
    $other = $this->makeTenant('inka');
    $token = $this->tokenFor($this->makeUser($other, 'manager'));
    expect($this->api($token)->getJson('/api/v1/inbox/conversations')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/inbox/conversations/{$code}")->assertNotFound();
});

it('§10-14 alcance: Ventas ve asignadas + sin asignar y solo toma; Operaciones ve pasajeros en viaje', function () {
    $mine = inbound($this, 'webchat', ['thread_id' => 'a', 'name' => 'Ana', 'email' => 'a@x.test', 'body' => 'Hola']);
    $free = inbound($this, 'email', ['thread_id' => 'b', 'name' => 'Beto', 'email' => 'b@x.test', 'body' => 'Consulta']);
    $other = inbound($this, 'webchat', ['thread_id' => 'c', 'name' => 'Carla', 'email' => 'c@x.test', 'body' => 'Hola']);
    $this->qPost('manager', "inbox/conversations/{$mine}/assign", ['user_id' => $this->users['u03']->id], cnvLock($mine))->assertOk();
    $this->qPost('manager', "inbox/conversations/{$other}/assign", ['user_id' => $this->users['u04']->id], cnvLock($other))->assertOk();

    $codes = collect($this->qGet('u03', 'inbox/conversations')->assertOk()->assertJsonPath('meta.mode', 'own')->json('data'))->pluck('code')->all();
    expect($codes)->toContain($mine)->toContain($free)->not->toContain($other);
    $this->qGet('u03', "inbox/conversations/{$other}")->assertForbidden()->assertJsonPath('code', 'INB-BR-04');
    $this->qPost('u03', "inbox/conversations/{$free}/assign", ['user_id' => $this->users['u04']->id], cnvLock($free))->assertForbidden()->assertJsonPath('code', 'INB-BR-04');
    $this->qPost('u03', "inbox/conversations/{$free}/assign", ['user_id' => 'me'], cnvLock($free))->assertOk()->assertJsonPath('data.assignee', 'U03');

    // Pasajero en viaje (Keller): reserva con hoy dentro de las fechas del viaje.
    $res = $this->makeReservation('u03');
    $this->admin()->table('booking_reservations')->where('id', $res['id'])->update(['travel_from' => now()->subDay()->toDateString(), 'travel_to' => now()->addDays(2)->toDateString()]);
    $keller = inbound($this, 'whatsapp', ['thread_id' => '41791234567', 'name' => 'Hans Keller', 'phone' => '+41791234567', 'body' => 'Huelga en Ollantaytambo?', 'reservation_id' => $res['id']]);
    $this->qPost('manager', "inbox/conversations/{$keller}/assign", ['user_id' => $this->users['u04']->id], cnvLock($keller))->assertOk();
    $ops = collect($this->qGet('ops', 'inbox/conversations')->assertOk()->json('data'))->pluck('code')->all();
    expect($ops)->toContain($keller)->not->toContain($free);
    $this->qPost('ops', "inbox/conversations/{$keller}/assign", ['user_id' => $this->users['u03']->id], cnvLock($keller))->assertForbidden();
    $this->qPost('ops', "inbox/conversations/{$keller}/assign", ['user_id' => $this->users['ops']->id], cnvLock($keller))->assertOk();
    $detail = $this->qGet('ops', "inbox/conversations/{$keller}")->assertOk()->json('data');
    expect($detail['actions']['lead'])->toContain('crm.leads.manage')->and($detail['actions']['quote'])->toContain('quotes.create');
});

it('§10-15 webhook WhatsApp: firma inválida → 401; mismo message_id dos veces → un mensaje; mensaje en conversación resuelta la reabre', function () {
    $payload = waPayload('wamid.AAA1', '41791234567', 'Hola, ¿mañana sale el tren?');
    $this->shop('POST', 'integrations/whatsapp/webhook', $payload, ['X-Hub-Signature-256' => 'sha256=malo'])->assertStatus(401);
    $this->shop('POST', 'integrations/whatsapp/webhook', $payload, waSign($payload))->assertOk()->assertJsonPath('data.results.0', 'procesado');
    $this->shop('POST', 'integrations/whatsapp/webhook', $payload, waSign($payload))->assertOk()->assertJsonPath('data.results.0', 'duplicado');
    $conv = $this->admin()->table('collab_conversations')->where('channel', 'whatsapp')->first();
    expect($this->admin()->table('collab_messages')->where('conversation_id', $conv->id)->where('direction', 'in')->count())->toBe(1)
        ->and($conv->contact_name)->toBe('Hans Keller');

    $this->qPost('manager', "inbox/conversations/{$conv->code}/resolve", [], cnvLock($conv->code))->assertOk()->assertJsonPath('data.status', 'resuelta');
    $next = waPayload('wamid.AAA2', '41791234567', 'Una consulta más');
    $this->shop('POST', 'integrations/whatsapp/webhook', $next, waSign($next))->assertOk();
    expect(cnv($conv->code)->status)->toBe('abierta')
        ->and(collect($this->auditRows('Conversación', $conv->code))->pluck('action'))->toContain('mensaje_entrante');

    $this->shop('GET', 'integrations/whatsapp/webhook?hub.mode=subscribe&hub.verify_token='.InboundWebhookController::SANDBOX_VERIFY_TOKEN.'&hub.challenge=123')->assertOk()->assertSee('123');
    $this->shop('GET', 'integrations/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=x&hub.challenge=123')->assertForbidden();
});

it('§10-16 ventana 24 h: texto libre fuera de ventana → 422 INB-BR-02; plantilla aprobada → 202', function () {
    $code = inbound($this, 'whatsapp', ['thread_id' => '51987000111', 'name' => 'Rosa', 'phone' => '+51987000111', 'body' => 'Hola']);
    $this->admin()->table('collab_conversations')->where('code', $code)->update(['last_inbound_at' => now()->subHours(30)]);
    $this->qPost('u03', "inbox/conversations/{$code}/messages", ['body' => 'Hola Rosa'], $this->idem())->assertStatus(422)->assertJsonPath('code', 'INB-BR-02');
    $template = $this->admin()->table('collab_reply_templates')->where('code', 'PLT-01')->first();
    $this->qPost('u03', "inbox/conversations/{$code}/messages", ['template_id' => $template->id], $this->idem())->assertStatus(202)
        ->assertJsonPath('data.messages.1.body', 'Hola Rosa, soy U03 de Andes S.A.C.. Gracias por escribirnos, ¿en qué podemos ayudarle?');
});

it('§10-17 1.ª respuesta: registra first_response_at, autoasigna y audita el cumplimiento del SLA', function () {
    $code = inbound($this, 'webchat', ['thread_id' => 'chat-1', 'name' => 'Pedro Ruiz', 'email' => 'pedro@correo.test', 'body' => 'Precio del Camino Inca?']);
    $c = cnv($code);
    expect($c->first_response_at)->toBeNull()->and($c->assignee_id)->toBeNull()
        ->and((int) round(now()->diffInMinutes($c->first_response_due_at, true)))->toBe(15);
    $this->qPost('u03', "inbox/conversations/{$code}/messages", ['body' => 'Hola Pedro, te paso la info'], $this->idem())->assertStatus(202)
        ->assertJsonPath('data.status', 'pendiente')->assertJsonPath('data.sla', 'cumplido');
    $c = cnv($code);
    expect($c->first_response_at)->not->toBeNull()->and($c->assignee_id)->toBe($this->users['u03']->id)
        ->and(collect($this->auditRows('Conversación', $code))->firstWhere('action', 'responder')->after_value)->toContain('1.ª respuesta dentro del SLA');

    // Escalamiento por SLA vencido (una sola alerta).
    $late = inbound($this, 'webchat', ['thread_id' => 'chat-2', 'name' => 'Sin respuesta', 'email' => 'z@x.test', 'body' => 'Hola?']);
    $this->admin()->table('collab_conversations')->where('code', $late)->update(['first_response_due_at' => now()->subMinute()]);
    $this->artisan('inbox:first-response-sla')->assertSuccessful();
    $this->artisan('inbox:first-response-sla')->assertSuccessful();
    expect($this->admin()->table('notif_messages')->where('event', 'inbox.sla')->where('user_id', $this->users['manager']->id)->count())->toBe(1);
});

it('§10-18 crear lead desde la conversación detecta duplicados por email/teléfono', function () {
    $lead = $this->makeLead('u03', ['email' => 'mario@correo.test', 'full_name' => 'Mario Díaz']);
    $code = inbound($this, 'form', ['name' => 'Mario Díaz', 'email' => 'MARIO@correo.test', 'subject' => 'Tour a Paracas', 'body' => 'Somos 4']);
    expect(cnv($code)->lead_id)->toBe($lead->id);
    $this->admin()->table('collab_conversations')->where('code', $code)->update(['lead_id' => null]);

    $this->qPost('u03', "inbox/conversations/{$code}/activities", ['type' => 'llamada', 'subject' => 'Llamar', 'due_at' => now()->addDay()->toDateTimeString()])
        ->assertStatus(422)->assertJsonPath('code', 'INB-BR-07');
    $this->qPost('u03', "inbox/conversations/{$code}/lead", [], $this->idem())->assertStatus(409)->assertJsonPath('code', 'CRM-BR-04');
    $created = $this->qPost('u03', "inbox/conversations/{$code}/lead", ['duplicate_resolution' => 'create_new'], $this->idem())->assertCreated()->json();
    $newLead = $this->admin()->table('crm_leads')->where('code', $created['meta']['lead'])->first();
    expect($newLead->notes)->toContain($code)->and($newLead->channel_code)->toBe('web');
    $this->qPost('u03', "inbox/conversations/{$code}/activities", ['type' => 'llamada', 'subject' => 'Llamar a Mario', 'due_at' => now()->addDay()->toDateTimeString()])->assertCreated();
    expect($this->admin()->table('crm_activities')->where('related_id', $newLead->id)->count())->toBeGreaterThanOrEqual(1);
});

it('§10-19/20 ticket del portal → conversación; responder y resolver actualizan el ticket; la nota interna no sale al portal', function () {
    $ticket = $this->qPost('customer', 'portal/client/tickets', ['subject' => 'Cambio de fecha', 'message' => '¿Puedo mover mi viaje?', 'category' => 'Cambio de fecha'])
        ->assertCreated()->json('data');
    $conv = $this->admin()->table('collab_conversations')->where('support_ticket_id', $ticket['id'])->first();
    expect($conv->channel)->toBe('portal')->and($conv->client_id)->toBe($this->clients['u03']->id)->and($conv->assignee_id)->toBe($this->users['u03']->id);

    $this->qPost('u03', "inbox/conversations/{$conv->code}/notes", ['body' => 'NOTA INTERNA: cliente sensible al precio'])->assertCreated();
    $this->qPost('u03', "inbox/conversations/{$conv->code}/messages", ['body' => 'Claro, revisamos disponibilidad.', 'resolve' => true], $this->idem())->assertStatus(202)
        ->assertJsonPath('data.status', 'resuelta');
    expect($this->admin()->table('channel_support_tickets')->where('id', $ticket['id'])->value('status'))->toBe('resuelta');

    $portal = $this->qGet('customer', 'portal/client/tickets')->assertOk()->json('data.0');
    expect($portal['status'])->toBe('resuelta')->and(collect($portal['replies'])->pluck('body')->all())->toBe(['Claro, revisamos disponibilidad.'])
        ->and(json_encode($portal))->not->toContain('NOTA INTERNA');

    $this->qPost('u03', "inbox/conversations/{$conv->code}/reopen", [], cnvLock($conv->code))->assertOk();
    expect($this->admin()->table('channel_support_tickets')->where('id', $ticket['id'])->value('status'))->toBe('en_proceso');
});

it('email entrante con token y formulario público; vincular solo clientes de la cartera; plantillas con variables válidas', function () {
    $this->shop('POST', 'integrations/email/inbound', ['message_id' => '<a1@mail>', 'from_email' => 'x@y.test', 'text' => 'Hola'], ['X-Inbound-Token' => 'malo'])->assertStatus(401);
    $this->shop('POST', 'integrations/email/inbound', ['message_id' => '<a1@mail>', 'from_email' => $this->clients['u04']->email, 'from_name' => 'Luis', 'subject' => 'Factura',
        'text' => 'Necesito mi factura'], ['X-Inbound-Token' => InboundWebhookController::SANDBOX_EMAIL_TOKEN])->assertStatus(202);
    $this->shop('POST', 'integrations/email/inbound', ['message_id' => '<a2@mail>', 'in_reply_to' => '<a1@mail>', 'from_email' => $this->clients['u04']->email, 'text' => 'Gracias'],
        ['X-Inbound-Token' => InboundWebhookController::SANDBOX_EMAIL_TOKEN])->assertStatus(202);
    $email = $this->admin()->table('collab_conversations')->where('channel', 'email')->first();
    expect($email->client_id)->toBe($this->clients['u04']->id)
        ->and($this->admin()->table('collab_messages')->where('conversation_id', $email->id)->count())->toBe(2);

    $this->shop('POST', 'public/contact', ['name' => 'Web', 'message' => 'Hola'])->assertStatus(422);
    $this->shop('POST', 'public/contact', ['name' => 'Web', 'email' => 'web@x.test', 'message' => 'Quiero info'])->assertStatus(202);
    $form = $this->admin()->table('collab_conversations')->where('channel', 'form')->first();

    $this->qPost('u03', "inbox/conversations/{$form->code}/link", ['client_code' => $this->clients['u04']->code])->assertForbidden()->assertJsonPath('code', 'INB-BR-06');
    $this->qPost('u03', "inbox/conversations/{$form->code}/link", ['client_code' => $this->clients['u03']->code])->assertOk()->assertJsonPath('data.contact.client.code', $this->clients['u03']->code);

    $this->as('admin')->postJson('/api/v1/inbox/templates', ['name' => 'Mala', 'body' => 'Hola {{cliente}}', 'channels' => ['email']])->assertStatus(422);
    $this->as('manager')->postJson('/api/v1/inbox/templates', ['name' => 'Ok', 'body' => 'Hola {{nombre}}', 'channels' => ['email']])->assertForbidden();
    $this->as('admin')->postJson('/api/v1/inbox/templates', ['name' => 'Ok', 'body' => 'Hola {{nombre}}', 'channels' => ['email']])->assertCreated()->assertJsonPath('data.code', 'PLT-05');

    // Adjuntos: el escaneo bloquea los infectados (INB-BR-11).
    $bad = $this->qPost('u03', 'inbox/attachments', ['file_name' => 'eicar.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1200])->assertCreated()->json('data');
    $this->qPost('u03', 'inbox/attachments', ['file_name' => 'x.exe', 'mime' => 'application/x-msdownload', 'size_bytes' => 1200])->assertStatus(422);
    $this->qPost('u03', "inbox/conversations/{$form->code}/messages", ['body' => 'Adjunto', 'attachments' => [$bad['id']]], $this->idem())->assertStatus(422)->assertJsonPath('code', 'INB-BR-11');
});
