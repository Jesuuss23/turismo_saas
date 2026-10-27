<?php

namespace App\Services\Integrations;

use App\Exceptions\ApiException;
use App\Services\Crm\LeadService;
use Illuminate\Support\Facades\DB;

/**
 * API pública del tenant `/api/v1/ext/*` (spec 13 §6, guard api-key). Expone solo campos públicos: nunca costos, márgenes
 * ni datos internos. El tenant sale de la API key (RLS), no del cliente.
 */
class ExtApiService
{
    public const PER_PAGE = 50;

    public function __construct(private readonly LeadService $leads) {}

    public function reservations(array $filters): array
    {
        $q = DB::table('booking_reservations as r')->leftJoin('crm_clients as c', 'c.id', '=', 'r.client_id')->whereNull('r.deleted_at')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('r.status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('r.travel_from', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('r.travel_from', '<=', $d))
            ->orderByDesc('r.created_at');

        return $this->page($q, ['r.*', 'c.code as client_code', 'c.name as client_name'], (int) ($filters['page'] ?? 1), fn ($r) => self::present($r));
    }

    public function reservation(string $code): array
    {
        $r = DB::table('booking_reservations as r')->leftJoin('crm_clients as c', 'c.id', '=', 'r.client_id')->whereNull('r.deleted_at')->where('r.code', $code)
            ->first(['r.*', 'c.code as client_code', 'c.name as client_name']) ?? throw ApiException::notFound();

        return self::present($r);
    }

    public static function present(object $r): array
    {
        return ['code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'channel' => $r->channel, 'travel_from' => $r->travel_from, 'travel_to' => $r->travel_to,
            'currency' => $r->currency, 'total' => (float) $r->total_amount, 'client' => isset($r->client_code) ? ['code' => $r->client_code, 'name' => $r->client_name] : null,
            'created_at' => $r->created_at];
    }

    public function products(array $filters): array
    {
        $q = DB::table('catalog_products')->whereNull('deleted_at')->where('status', 'ACTIVE')
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where('name', 'ilike', '%'.addcslashes($s, '%_').'%'))->orderBy('code');

        return $this->page($q, ['*'], (int) ($filters['page'] ?? 1), fn ($p) => ['code' => $p->code, 'name' => $p->name, 'slug' => $p->slug,
            'short_description' => $p->short_description, 'duration' => $p->duration_value ? "{$p->duration_value} {$p->duration_unit}" : null, 'modality' => $p->modality,
            'min_pax' => $p->minimum_passengers, 'max_pax' => $p->maximum_passengers, 'bookable' => (bool) $p->is_bookable]);
    }

    public function clients(array $filters): array
    {
        $q = DB::table('crm_clients')->whereNull('deleted_at')
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where('name', 'ilike', '%'.addcslashes($s, '%_').'%'))->orderBy('code');

        return $this->page($q, ['*'], (int) ($filters['page'] ?? 1), fn ($c) => ['code' => $c->code, 'kind' => $c->kind, 'name' => $c->name, 'email' => $c->email,
            'phone' => $c->phone, 'country' => $c->country, 'city' => $c->city]);
    }

    /** POST /ext/leads: ingreso de sistema (canal web, origen formulario web). Con coincidencias se registra igual y se anota. */
    public function createLead(array $data): array
    {
        $payload = array_filter([
            'full_name' => trim((string) ($data['full_name'] ?? '')), 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null, 'channel_code' => 'web', 'source_code' => 'formulario_web', 'interest_code' => $data['interest_code'] ?? 'paquete',
            'destination_label' => $data['destination'] ?? null, 'travel_date' => $data['travel_date'] ?? null, 'pax' => (int) ($data['pax'] ?? 0),
            'notes' => isset($data['notes']) ? 'API pública: '.$data['notes'] : 'Ingresado por la API pública',
        ], fn ($v) => $v !== null && $v !== '');
        if (($payload['full_name'] ?? '') === '') {
            throw ApiException::invalid('full_name', 'El nombre es obligatorio.');
        }
        if (empty($payload['email']) && empty($payload['phone'])) {
            throw ApiException::invalid('email', 'Registre al menos correo o teléfono.');
        }
        if (isset($payload['email']) && ! filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid('email', 'Correo inválido.');
        }
        $lead = $this->leads->create($payload, null, 'create')['lead'];

        return ['code' => $lead->code, 'full_name' => $lead->full_name, 'status' => $lead->status, 'created_at' => $lead->created_at];
    }

    private function page($query, array $columns, int $page, callable $map): array
    {
        $page = max(1, $page);
        $total = (clone $query)->count();
        $rows = $query->forPage($page, self::PER_PAGE)->get($columns)->map($map)->all();

        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total, 'last_page' => max(1, (int) ceil($total / self::PER_PAGE))]];
    }
}
