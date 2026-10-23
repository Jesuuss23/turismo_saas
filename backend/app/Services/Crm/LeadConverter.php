<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Activity;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Conversión lead → cliente (nuevo o existente) + oportunidad (+ reunión opcional) en una sola
 * transacción (PROC-005, CRM-BR-12, 15–19). La idempotencia la da el middleware `idempotent`.
 */
class LeadConverter
{
    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmCatalog $catalog,
        private readonly CrmClock $clock,
        private readonly ClientService $clients,
        private readonly OpportunityService $opportunities,
        private readonly ActivityService $activities,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** CRM-BR-18: Corporativo si interés corporativo; Grupos si promoción o pax ≥ 10; si no Retail. */
    public static function suggestedSegment(Lead $lead): string
    {
        return match (true) {
            $lead->interest_code === 'corporativo' => 'corporativo',
            $lead->interest_code === 'promocion' || $lead->pax >= 10 => 'grupos',
            default => 'retail',
        };
    }

    /**
     * @return array{client: Client, opportunity: Opportunity, activity: ?Activity, client_created: bool}
     */
    public function convert(Lead $lead, array $payload, User $actor): array
    {
        $this->access->ensureLeadManageable($lead, $actor);
        if ($lead->status !== Lead::QUALIFIED) {
            throw ApiException::rule('CRM-BR-12', 'PROC-005: solo los leads calificados se pueden convertir');
        }

        return DB::transaction(function () use ($lead, $payload, $actor) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($lead->status !== Lead::QUALIFIED) {
                throw ApiException::rule('CRM-BR-12', 'PROC-005: solo los leads calificados se pueden convertir');
            }

            [$client, $created] = $payload['client_mode'] === 'existing'
                ? [$this->linkExisting($lead, $payload['client_id'], $actor), false]
                : [$this->createClient($lead, $payload['client'], $actor), true];

            $opp = $payload['opportunity'];
            $opportunity = $this->opportunities->create([
                'client_id' => $client->id,
                'owner_id' => $opp['owner_id'] ?? $lead->owner_id ?? $actor->id,
                'title' => $opp['title'],
                'stage' => $opp['stage'] ?? 'descubrimiento',
                'amount' => $opp['amount'],
                'currency' => $opp['currency'],
                'expected_close_date' => $opp['expected_close_date'],
                'destination_label' => $lead->destination_label,
                'pax' => $lead->pax,
                'product_id' => $lead->product_id,
                'notes' => $lead->notes,
            ], $actor, $lead->id, $lead->code);

            $activity = null;
            if (! empty($payload['schedule_discovery_meeting'])) {
                $activity = $this->activities->schedule('reunion', "Reunión de descubrimiento — {$client->name}", $opportunity->owner_id,
                    $this->clock->tomorrowAt(10, 2), 'opportunity', $opportunity->id, $actor);
            }

            $before = $lead->status;
            $lead->forceFill([
                'status' => Lead::CONVERTED,
                'converted_client_id' => $client->id,
                'converted_opportunity_id' => $opportunity->id,
                'converted_at' => now(),
                'converted_by' => $actor->id,
            ])->save();
            $this->audit->log('Lead', $lead->code, 'convertir', $before, "{$client->code} + {$opportunity->code}", actor: $actor);

            $this->notify->push('lead.convertido', 'portal', $opportunity->owner, "Lead {$lead->code} convertido en {$opportunity->code}", null, [
                'lead_code' => $lead->code, 'client_id' => $client->id, 'related_type' => 'opportunity', 'related_id' => $opportunity->id,
            ]);

            return ['client' => $client, 'opportunity' => $opportunity, 'activity' => $activity, 'client_created' => $created];
        });
    }

    /** CRM-BR-18: vincular; si el correo del lead no está en el cliente ni en sus contactos, se agrega como contacto no principal. */
    private function linkExisting(Lead $lead, string $clientId, User $actor): Client
    {
        $client = Client::whereKey($clientId)->first() ?? throw ApiException::invalid('client_id', 'Seleccione un cliente válido');
        $this->access->ensureClient($client, $actor);

        $email = ContactNormalizer::email($lead->email);
        if ($email !== null && $client->email_norm !== $email) {
            $known = ClientContact::where('client_id', $client->id)->get()->contains(fn ($c) => ContactNormalizer::email($c->email) === $email);
            if (! $known) {
                $this->clients->addContact($client, [
                    'name' => $lead->full_name, 'role' => "Contacto (desde {$lead->code})", 'email' => $lead->email, 'phone' => $lead->phone,
                ], $actor, $lead->id);
            }
        }

        return $client;
    }

    private function createClient(Lead $lead, array $data, User $actor): Client
    {
        $kind = $data['kind'];
        $contact = $kind === 'empresa'
            ? ['name' => $lead->full_name, 'role' => "Contacto (desde {$lead->code})", 'email' => $lead->email, 'phone' => $lead->phone]
            : null;

        return $this->clients->create([
            'kind' => $kind,
            'name' => $data['name'] ?? ($kind === 'empresa' ? $lead->company : $lead->full_name) ?? $lead->full_name,
            'doc_type' => $data['doc_type'],
            'doc_number' => $data['doc_number'],
            'email' => $lead->email,
            'phone' => $lead->phone,
            'country' => $data['country'] ?? 'Perú',
            'city' => $data['city'] ?? null,
            'segment_code' => $data['segment'] ?? self::suggestedSegment($lead),
            'tags' => [$this->catalog->label('lead_source', $lead->source_code)],
            // Owner = owner del lead (o el actor); Ventas siempre queda como owner.
            'owner_id' => $lead->owner_id ?? $actor->id,
        ], $actor, confirmDuplicate: true, contact: $contact, sourceLeadId: $lead->id);
    }
}
