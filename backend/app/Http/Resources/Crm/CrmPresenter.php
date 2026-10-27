<?php

namespace App\Http\Resources\Crm;

use App\Models\Crm\Activity;
use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadQualification;
use App\Models\Crm\Opportunity;
use App\Models\Crm\Survey;
use App\Models\Iam\User;
use App\Services\Crm\ActivityService;
use App\Services\Crm\AfterSalesService;
use App\Services\Crm\CrmCatalog;
use App\Services\Crm\OpportunityService;
use Illuminate\Support\Collection;

/** Representación JSON de los registros CRM (etiquetas de catálogo incluidas para la UI). */
class CrmPresenter
{
    /** @var array<string, ?array> */
    private array $users = [];

    public function __construct(private readonly CrmCatalog $catalog) {}

    /** Precarga nombres de usuarios para evitar N+1. */
    public function preloadUsers(iterable $ids): static
    {
        $missing = collect($ids)->filter()->unique()->reject(fn ($id) => array_key_exists($id, $this->users));
        if ($missing->isNotEmpty()) {
            User::whereIn('id', $missing->all())->get(['id', 'code', 'name'])
                ->each(fn (User $u) => $this->users[$u->id] = ['id' => $u->id, 'code' => $u->code, 'name' => $u->name]);
            $missing->each(fn ($id) => $this->users[$id] ??= null);
        }

        return $this;
    }

    public function user(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $this->preloadUsers([$id]);

        return $this->users[$id];
    }

    public function lead(Lead $l): array
    {
        [$step, $failed] = $l->flowStep();

        return [
            'id' => $l->id,
            'code' => $l->code,
            'full_name' => $l->full_name,
            'company' => $l->company,
            'email' => $l->email,
            'phone' => $l->phone,
            'channel' => ['code' => $l->channel_code, 'label' => $this->catalog->label('lead_channel', $l->channel_code)],
            'source' => ['code' => $l->source_code, 'label' => $this->catalog->label('lead_source', $l->source_code)],
            'interest' => ['code' => $l->interest_code, 'label' => $this->catalog->label('lead_interest', $l->interest_code)],
            'destination_id' => $l->destination_id,
            'destination_label' => $l->destination_label,
            'product_id' => $l->product_id,
            'travel_date' => $l->travel_date?->toDateString(),
            'pax' => $l->pax,
            'status' => $l->status,
            'status_label' => Lead::STATUS_LABELS[$l->status],
            'owner' => $this->user($l->owner_id),
            'score' => $l->score,
            'notes' => $l->notes,
            'duplicate_of' => $l->duplicate_of_type ? ['type' => $l->duplicate_of_type, 'id' => $l->duplicate_of_id] : null,
            'validated_at' => $l->validated_at,
            'assigned_at' => $l->assigned_at,
            'taken_by' => $this->user($l->taken_by),
            'first_contact_at' => $l->first_contact_at,
            'discard' => $l->discard_reason_code ? [
                'reason' => ['code' => $l->discard_reason_code, 'label' => $this->catalog->label('discard_reason', $l->discard_reason_code)],
                'detail' => $l->discard_detail, 'at' => $l->discarded_at, 'step' => $l->discarded_at_step,
            ] : null,
            'conversion' => $l->converted_at ? [
                'client_id' => $l->converted_client_id, 'opportunity_id' => $l->converted_opportunity_id,
                'at' => $l->converted_at, 'by' => $this->user($l->converted_by),
            ] : null,
            'flow' => ['step' => $step, 'failed' => $failed],
            'lock_version' => $l->lock_version,
            'created_by' => $this->user($l->created_by),
            'created_at' => $l->created_at,
        ];
    }

    public function qualification(?LeadQualification $q): ?array
    {
        return $q === null ? null : [
            'id' => $q->id,
            'budget' => $q->budget, 'authority' => $q->authority, 'need' => $q->need, 'timeline' => $q->timeline,
            'score' => $q->score,
            'declared_amount' => $q->declared_amount, 'currency' => $q->currency, 'notes' => $q->notes,
            'qualified_by' => $this->user($q->qualified_by), 'qualified_at' => $q->qualified_at,
        ];
    }

    public function opportunity(Opportunity $o, ?Client $client = null): array
    {
        $client ??= $o->relationLoaded('client') ? $o->client : null;

        return [
            'id' => $o->id,
            'code' => $o->code,
            'title' => $o->title,
            'client' => $client ? ['id' => $client->id, 'code' => $client->code, 'name' => $client->name] : ['id' => $o->client_id],
            'stage' => $o->stage,
            'stage_label' => $this->catalog->stageName($o->stage),
            'amount' => (float) $o->amount,
            'currency' => $o->currency,
            'probability' => $o->probability,
            'weighted' => round((float) $o->amount * $o->probability / 100, 2),
            'expected_close_date' => $o->expected_close_date?->toDateString(),
            'closed_at' => $o->closed_at?->toDateString(),
            'owner' => $this->user($o->owner_id),
            'destination_label' => $o->destination_label,
            'pax' => $o->pax,
            'lead_id' => $o->lead_id,
            'product_id' => $o->product_id,
            'origin' => $o->origin,
            'notes' => $o->notes,
            'lost' => $o->lost_reason_code ? [
                'reason' => ['code' => $o->lost_reason_code, 'label' => $this->catalog->label('lost_reason', $o->lost_reason_code)],
                'detail' => $o->lost_detail, 'at_stage' => $o->lost_at_stage,
            ] : null,
            'is_closed' => $o->isClosed(),
            'reopen_stage' => $o->isClosed() ? OpportunityService::reopenStage($o) : null,
            'lock_version' => $o->lock_version,
            'created_at' => $o->created_at,
        ];
    }

    public function activity(Activity $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'type' => $a->type,
            'type_label' => ActivityService::TYPE_LABELS[$a->type],
            'subject' => $a->subject,
            'description' => $a->description,
            'related' => $a->related_type ? ['type' => $a->related_type, 'id' => $a->related_id] : null,
            'owner' => $this->user($a->owner_id),
            'due_at' => $a->due_at,
            'status' => $a->effectiveStatus(),
            'stored_status' => $a->status,
            'result' => $a->result,
            'completed_at' => $a->completed_at,
            'completed_by' => $this->user($a->completed_by),
            'auto_closed' => $a->auto_closed,
            'previous_activity_id' => $a->previous_activity_id,
            'merged_from_lead_id' => $a->merged_from_lead_id,
            'lock_version' => $a->lock_version,
            'created_at' => $a->created_at,
        ];
    }

    public function client(Client $c, array $stats = []): array
    {
        $primary = $c->relationLoaded('primaryContact') ? $c->primaryContact : null;

        return [
            'id' => $c->id,
            'code' => $c->code,
            'kind' => $c->kind,
            'name' => $c->name,
            'doc_type' => $c->doc_type,
            'doc_number' => $c->doc_number,
            'email' => $c->email,
            'phone' => $c->phone,
            'country' => $c->country,
            'city' => $c->city,
            'birth_date' => $c->birth_date?->toDateString(),
            'segment' => ['code' => $c->segment_code, 'label' => $this->catalog->label('client_segment', $c->segment_code)],
            'owner' => $this->user($c->owner_id),
            'tags' => $c->tags,
            'nps' => $c->nps_last,
            'contact_name' => $primary?->name,
            'source_lead_id' => $c->source_lead_id,
            'b2b_agency_id' => $c->b2b_agency_id,
            'reservations_count' => $stats['reservations_count'] ?? 0,
            'total_pen' => $stats['total_pen'] ?? 0,
            'balance_pen' => $stats['balance_pen'] ?? 0,
            'last_purchase_date' => $stats['last_purchase_date'] ?? null,
            'lock_version' => $c->lock_version,
            'created_at' => $c->created_at,
        ];
    }

    public function contact(ClientContact $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'role' => $c->role, 'email' => $c->email, 'phone' => $c->phone, 'is_primary' => $c->is_primary];
    }

    public function survey(Survey $s, ?Client $client = null): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'client' => $client ? ['id' => $client->id, 'code' => $client->code, 'name' => $client->name] : ['id' => $s->client_id],
            'reservation_id' => $s->reservation_id,
            'score' => $s->score,
            'category' => $s->category,
            'comment' => $s->comment,
            'channel' => ['code' => $s->channel_code, 'label' => $this->catalog->label('survey_channel', $s->channel_code)],
            'responded_at' => $s->responded_at,
            'recorded_by' => $this->user($s->recorded_by),
        ];
    }

    public function case(AfterSalesCase $c, bool $withEvents = false): array
    {
        $client = $c->relationLoaded('client') ? $c->client : null;

        return [
            'id' => $c->id,
            'code' => $c->code,
            'client' => $client ? ['id' => $client->id, 'code' => $client->code, 'name' => $client->name] : ['id' => $c->client_id],
            'reservation_id' => $c->reservation_id,
            'survey_id' => $c->survey_id,
            'origin' => $c->origin,
            'is_book' => (bool) $c->is_book, 'book_number' => $c->book_number, 'book_status' => $c->book_status,
            'type' => ['code' => $c->type_code, 'label' => $this->catalog->label('case_type', $c->type_code)],
            'priority' => $c->priority,
            'subject' => $c->subject,
            'description' => $c->description,
            'status' => $c->status,
            'status_label' => AfterSalesService::STATUS_LABELS[$c->status],
            'owner' => $this->user($c->owner_id),
            'resolution' => $c->resolution,
            'resolved_at' => $c->resolved_at,
            'closed_at' => $c->closed_at,
            'lock_version' => $c->lock_version,
            'created_at' => $c->created_at,
            'events' => $withEvents ? $c->events->map(fn ($e) => [
                'event' => $e->event, 'from_status' => $e->from_status, 'to_status' => $e->to_status,
                'message' => $e->message, 'user' => $this->user($e->user_id), 'created_at' => $e->created_at,
            ])->all() : null,
        ];
    }

    /** @param Collection<int, mixed> $rows */
    public function many(Collection $rows, string $method): array
    {
        $this->preloadUsers($rows->flatMap(fn ($r) => [$r->owner_id ?? null, $r->created_by ?? null])->all());

        return $rows->map(fn ($r) => $this->{$method}($r))->values()->all();
    }
}
