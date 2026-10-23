<?php

namespace App\Services\Crm;

use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use Illuminate\Support\Collection;

/**
 * Exportación CSV de recursos CRM respetando el alcance del rol (CRM-BR-47, CRM-T29).
 * Cada recurso exige el mismo permiso que su listado (§12-21: hoy no se exige reports.export).
 */
class CrmExporter
{
    public const RESOURCES = [
        'crm.leads' => ['crm.leads.view'],
        'crm.opportunities' => ['crm.opportunities.manage'],
        'crm.clients' => ['crm.clients.manage', 'finance.receivables'],
        'crm.cases' => ['crm.clients.manage'],
        'crm.loyalty_segments' => ['crm.clients.manage'],
    ];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmCatalog $catalog,
        private readonly LoyaltySegments $segments,
    ) {}

    /** @return array{0: list<string>, 1: Collection<int, list<mixed>>} encabezados y filas */
    public function rows(string $resource, User $user, array $filters = []): array
    {
        $owner = fn ($q, $column = 'owner_id') => match ($filters['owner'] ?? null) {
            null, '', 'any' => $q,
            'me' => $q->where($column, $user->id),
            'none' => $q->whereNull($column),
            default => $q->where($column, $filters['owner']),
        };
        $names = User::pluck('name', 'id');

        return match ($resource) {
            'crm.leads' => [
                ['Código', 'Nombre', 'Empresa', 'Correo', 'Teléfono', 'Fuente', 'Canal', 'Interés', 'Destino', 'Pax', 'Estado', 'Score', 'Responsable', 'Creado'],
                $owner($this->access->leads(Lead::query(), $user))
                    ->when($filters['status'] ?? null, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
                    ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source_code', $s))
                    ->orderByDesc('created_at')->get()
                    ->map(fn (Lead $l) => [$l->code, $l->full_name, $l->company, $l->email, $l->phone,
                        $this->catalog->label('lead_source', $l->source_code), $this->catalog->label('lead_channel', $l->channel_code),
                        $this->catalog->label('lead_interest', $l->interest_code), $l->destination_label, $l->pax, Lead::STATUS_LABELS[$l->status],
                        $l->score, $names[$l->owner_id] ?? 'Sin asignar', $l->created_at?->toDateTimeString()]),
            ],
            'crm.opportunities' => [
                ['Código', 'Título', 'Cliente', 'Etapa', 'Monto', 'Moneda', 'Probabilidad', 'Cierre esperado', 'Cerrada', 'Responsable'],
                $owner($this->access->opportunities(Opportunity::query(), $user))->with('client:id,name')
                    ->when($filters['stage'] ?? null, fn ($q, $s) => $q->whereIn('stage', explode(',', $s)))
                    ->orderByDesc('created_at')->get()
                    ->map(fn (Opportunity $o) => [$o->code, $o->title, $o->client?->name, $this->catalog->stageName($o->stage), (float) $o->amount, $o->currency,
                        $o->probability, $o->expected_close_date?->toDateString(), $o->closed_at?->toDateString(), $names[$o->owner_id] ?? '']),
            ],
            'crm.clients' => [
                ['Código', 'Tipo', 'Nombre', 'Documento', 'Correo', 'Teléfono', 'Ciudad', 'País', 'Segmento', 'NPS', 'Responsable'],
                $owner($this->access->clients(Client::query(), $user), 'crm_clients.owner_id')
                    ->when($filters['segment'] ?? null, fn ($q, $s) => $q->where('segment_code', $s))
                    ->orderBy('name')->get()
                    ->map(fn (Client $c) => [$c->code, $c->kind, $c->name, "{$c->doc_type} {$c->doc_number}", $c->email, $c->phone, $c->city, $c->country,
                        $this->catalog->label('client_segment', $c->segment_code), $c->nps_last, $names[$c->owner_id] ?? '']),
            ],
            'crm.cases' => [
                ['Código', 'Cliente', 'Tipo', 'Prioridad', 'Asunto', 'Estado', 'Origen', 'Responsable', 'Creado'],
                $owner($this->access->cases(AfterSalesCase::query(), $user), 'crm_cases.owner_id')->with('client:id,name')
                    ->when($filters['status'] ?? null, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
                    ->orderByDesc('created_at')->get()
                    ->map(fn (AfterSalesCase $c) => [$c->code, $c->client?->name, $this->catalog->label('case_type', $c->type_code), $c->priority,
                        $c->subject, $c->status, $c->origin, $names[$c->owner_id] ?? '', $c->created_at?->toDateTimeString()]),
            ],
            'crm.loyalty_segments' => [
                ['Código', 'Cliente', 'Segmento', 'Última compra', 'Días sin comprar', 'Destinos', 'Total comprado (PEN)', 'NPS', 'Oportunidad abierta', 'Responsable'],
                $this->segments->rows($owner($this->access->clients(Client::query(), $user), 'crm_clients.owner_id'), $user, $filters)
                    ->map(fn ($r) => [$r['client']->code, $r['client']->name, $this->catalog->label('client_segment', $r['client']->segment_code),
                        $r['stats']['last_purchase_date'] ?? '', $r['days_since_purchase'], implode(', ', $r['destinations']),
                        $r['stats']['total_pen'] ?? 0, $r['client']->nps_last, $r['has_open_opportunity'] ? 'Sí' : 'No', $names[$r['client']->owner_id] ?? '']),
            ],
        };
    }

    /** CSV con BOM UTF-8 (Excel) y neutralización de fórmulas (CSV injection). */
    public static function toCsv(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'{$v}" : $v, $row), ',', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
