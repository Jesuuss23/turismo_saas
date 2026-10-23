<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Maestro de clientes, contactos y comunicaciones (spec 01 PROC-008, CRM-BR-15..18, 35, 36). */
class ClientService
{
    public const FIELDS = ['kind', 'name', 'doc_type', 'doc_number', 'email', 'phone', 'country', 'city', 'birth_date', 'segment_code', 'tags', 'b2b_agency_id'];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmCatalog $catalog,
        private readonly CrmUsers $users,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /**
     * Alta. CRM-BR-16 (documento único, bloqueante) y CRM-BR-17 (correo/teléfono, advertencia).
     *
     * @param  array{name: string, role?: ?string, email?: ?string, phone?: ?string}|null  $contact  contacto principal
     */
    public function create(array $data, User $actor, bool $confirmDuplicate = false, ?array $contact = null, ?string $sourceLeadId = null): Client
    {
        $data = $this->prepare($data);
        $this->assertDocumentFree($data['doc_type'], $data['doc_number']);
        if (! $confirmDuplicate && ($soft = $this->softDuplicates($data['email'] ?? null, $data['phone'] ?? null)) !== []) {
            throw ApiException::rule('CRM-BR-17', 'Ya existe un cliente con el mismo correo o teléfono. ¿Guardar igualmente?', ['duplicates' => $soft]);
        }

        $ownerId = $this->access->ownOnly($actor) ? $actor->id : $this->users->assertAssignable($data['owner_id'] ?? $actor->id)->id;

        return DB::transaction(function () use ($data, $ownerId, $contact, $sourceLeadId, $actor) {
            $client = Client::create(Arr::only($data, self::FIELDS) + [
                'code' => $this->sequences->next('client'),
                'owner_id' => $ownerId,
                'source_lead_id' => $sourceLeadId,
                'tags' => $data['tags'] ?? [],
            ]);
            if ($contact !== null && filled($contact['name'] ?? null)) {
                ClientContact::create(Arr::only($contact, ['name', 'role', 'email', 'phone']) + [
                    'client_id' => $client->id, 'is_primary' => true, 'source_lead_id' => $sourceLeadId,
                ]);
            }
            $this->audit->log('Cliente', $client->code, 'crear', null, "{$client->name} · ".$this->catalog->label('client_segment', $client->segment_code), actor: $actor);

            return $client;
        });
    }

    public function update(Client $client, array $data, User $actor, bool $confirmDuplicate = false): Client
    {
        $this->access->ensureClient($client, $actor);
        $data = $this->prepare($data + $client->only(['kind', 'country', 'doc_type', 'doc_number', 'segment_code']), $client);
        $client->fill(Arr::only($data, self::FIELDS));
        if (! $client->email && ! $client->phone) {
            throw ApiException::invalid('email', 'Registre al menos correo o teléfono del cliente');
        }
        if ($client->isDirty(['doc_type', 'doc_number'])) {
            $this->assertDocumentFree($client->doc_type, $client->doc_number, $client->id);
        }
        if ($client->isDirty(['email', 'phone']) && ! $confirmDuplicate
            && ($soft = $this->softDuplicates($client->email, $client->phone, $client->id)) !== []) {
            throw ApiException::rule('CRM-BR-17', 'Ya existe un cliente con el mismo correo o teléfono. ¿Guardar igualmente?', ['duplicates' => $soft]);
        }

        $changed = array_keys($client->getDirty());
        if ($changed !== []) {
            DB::transaction(function () use ($client, $changed, $actor) {
                $client->save();
                $this->audit->log('Cliente', $client->code, 'editar', implode(', ', $changed), 'actualizado', actor: $actor);
            });
        }

        return $client->refresh();
    }

    public function reassign(Client $client, string $ownerId, ?string $note, User $actor): Client
    {
        $this->access->ensureSupervisor($actor, 'reasignar clientes');
        $owner = $this->users->assertAssignable($ownerId);
        if ($owner->id === $client->owner_id) {
            throw ApiException::invalid('owner_id', 'Seleccione un asesor distinto al actual');
        }

        return DB::transaction(function () use ($client, $owner, $note, $actor) {
            $previous = $client->owner?->name;
            $client->forceFill(['owner_id' => $owner->id])->save();
            $this->audit->log('Cliente', $client->code, 'reasignar', $previous, $owner->name.($note ? " · {$note}" : ''), actor: $actor);
            if ($owner->id !== $actor->id) {
                $this->notify->push('cliente.reasignado', 'portal', $owner, "Cliente {$client->code} reasignado a su cartera", null, [
                    'client_id' => $client->id, 'related_type' => 'client', 'related_id' => $client->id,
                ]);
            }

            return $client;
        });
    }

    /** CRM-BR-36: el primer contacto es el principal. */
    public function addContact(Client $client, array $data, User $actor, ?string $sourceLeadId = null): ClientContact
    {
        return DB::transaction(function () use ($client, $data, $actor, $sourceLeadId) {
            $isFirst = ! ClientContact::where('client_id', $client->id)->exists();
            $contact = ClientContact::create(Arr::only($data, ['name', 'role', 'email', 'phone']) + [
                'client_id' => $client->id, 'is_primary' => $isFirst, 'source_lead_id' => $sourceLeadId,
            ]);
            $this->audit->log('Cliente', $client->code, 'agregar_contacto', null, $contact->name, actor: $actor);

            return $contact;
        });
    }

    public function makePrimary(Client $client, ClientContact $contact, User $actor): ClientContact
    {
        if ($contact->client_id !== $client->id) {
            throw ApiException::notFound();
        }

        return DB::transaction(function () use ($client, $contact, $actor) {
            $previous = ClientContact::where('client_id', $client->id)->where('is_primary', true)->value('name');
            ClientContact::where('client_id', $client->id)->where('is_primary', true)->update(['is_primary' => false]);
            $contact->forceFill(['is_primary' => true])->save();
            $this->audit->log('Cliente', $client->code, 'contacto_principal', $previous, $contact->name, actor: $actor);

            return $contact;
        });
    }

    /** CRM-BR-35: Email exige correo; WhatsApp, teléfono; Portal, un usuario de portal vinculado. */
    public function communicate(Client $client, string $channel, string $subject, ?string $body, User $actor): void
    {
        $to = match ($channel) {
            'email' => $client->email ?? throw ApiException::invalid('channel', 'El cliente no tiene correo registrado'),
            'whatsapp' => $client->phone ?? throw ApiException::invalid('channel', 'El cliente no tiene teléfono registrado'),
            'portal' => User::where('client_id', $client->id)->where('status', User::ACTIVE)->first()
                ?? throw ApiException::invalid('channel', 'El cliente no tiene acceso al portal'),
        };

        DB::transaction(function () use ($client, $channel, $subject, $body, $to, $actor) {
            $this->notify->push('cliente.comunicacion', $channel, $to, $subject, $body, [
                'client_id' => $client->id, 'related_type' => 'client', 'related_id' => $client->id, 'sent_by' => $actor->id,
            ]);
            $this->audit->log('Cliente', $client->code, 'comunicacion', $channel, $subject, actor: $actor);
        });
    }

    /** Coincidencias blandas por correo/teléfono contra otros clientes (CRM-BR-17). */
    public function softDuplicates(?string $email, ?string $phone, ?string $excludeId = null): array
    {
        $email = ContactNormalizer::email($email);
        $phone = ContactNormalizer::phone($phone);
        if ($email === null && $phone === null) {
            return [];
        }

        return Client::query()
            ->where(fn ($q) => $q->when($email, fn ($q) => $q->orWhere('email_norm', $email))->when($phone, fn ($q) => $q->orWhere('phone_norm', $phone)))
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->limit(10)->get(['id', 'code', 'name', 'email_norm', 'phone_norm'])
            ->map(fn (Client $c) => [
                'type' => 'client', 'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                'fields' => array_values(array_filter([$email && $c->email_norm === $email ? 'correo' : null, $phone && $c->phone_norm === $phone ? 'teléfono' : null])),
            ])->all();
    }

    public function findByDocument(string $type, string $number, ?string $excludeId = null): ?Client
    {
        return Client::where('doc_type', $type)->where('doc_number', ClientDocument::normalize($number))
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))->first();
    }

    private function assertDocumentFree(string $type, string $number, ?string $excludeId = null): void
    {
        if ($existing = $this->findByDocument($type, $number, $excludeId)) {
            throw ApiException::rule('CRM-BR-16', "Ya existe un cliente con {$type} {$number} ({$existing->code}). Vincúlelo", [
                'client' => ['id' => $existing->id, 'code' => $existing->code, 'name' => $existing->name],
            ]);
        }
    }

    private function prepare(array $data, ?Client $current = null): array
    {
        if (array_key_exists('doc_number', $data)) {
            $data['doc_number'] = ClientDocument::normalize($data['doc_number']);
        }
        $data['country'] = $data['country'] ?? $current?->country ?? 'Perú';
        ClientDocument::assertValid($data['kind'], $data['country'], $data['doc_type'], $data['doc_number']);
        $this->catalog->assertActive('client_segment', $data['segment_code'] ?? null, 'segment_code');
        if (isset($data['tags'])) {
            $data['tags'] = array_values(array_unique(array_filter(array_map('trim', $data['tags']))));
        }

        return $data;
    }
}
