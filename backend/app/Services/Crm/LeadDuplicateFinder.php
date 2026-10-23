<?php

namespace App\Services\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\Lead;

/**
 * CRM-BR-03: coincidencias por correo normalizado o últimos 9 dígitos del teléfono, contra
 * leads (excepto el propio) y clientes. Se busca en todo el tenant: un duplicado de otro
 * asesor también es duplicado (la respuesta solo expone datos mínimos).
 */
class LeadDuplicateFinder
{
    public function __construct(private readonly CrmCatalog $catalog) {}

    /**
     * @return list<array{type: string, id: string, code: string, name: string, status: string, owner_id: ?string, fields: list<string>}>
     */
    public function find(?string $email, ?string $phone, ?string $excludeLeadId = null): array
    {
        $email = ContactNormalizer::email($email);
        $phone = ContactNormalizer::phone($phone);
        if ($email === null && $phone === null) {
            return [];
        }

        $match = function ($q) use ($email, $phone) {
            $q->where(function ($q) use ($email, $phone) {
                if ($email !== null) {
                    $q->orWhere('email_norm', $email);
                }
                if ($phone !== null) {
                    $q->orWhere('phone_norm', $phone);
                }
            });
        };

        $fields = function ($row) use ($email, $phone) {
            $f = [];
            if ($email !== null && $row->email_norm === $email) {
                $f[] = 'correo';
            }
            if ($phone !== null && $row->phone_norm === $phone) {
                $f[] = 'teléfono';
            }

            return $f;
        };

        $leads = Lead::query()->tap($match)
            ->when($excludeLeadId, fn ($q) => $q->whereKeyNot($excludeLeadId))
            ->orderBy('created_at')->limit(20)
            ->get(['id', 'code', 'full_name', 'status', 'owner_id', 'email_norm', 'phone_norm']);

        $clients = Client::query()->tap($match)->orderBy('created_at')->limit(20)
            ->get(['id', 'code', 'name', 'segment_code', 'owner_id', 'email_norm', 'phone_norm']);

        return [
            ...$leads->map(fn (Lead $l) => [
                'type' => 'lead', 'id' => $l->id, 'code' => $l->code, 'name' => $l->full_name,
                'status' => $l->status, 'owner_id' => $l->owner_id, 'fields' => $fields($l),
            ])->all(),
            ...$clients->map(fn (Client $c) => [
                'type' => 'client', 'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                'status' => $this->catalog->label('client_segment', $c->segment_code), 'owner_id' => $c->owner_id, 'fields' => $fields($c),
            ])->all(),
        ];
    }

    /** "Coincide con X (correo y teléfono)". */
    public static function describe(array $match): string
    {
        return "{$match['code']} ({$match['name']}) por ".implode(' y ', $match['fields']);
    }
}
