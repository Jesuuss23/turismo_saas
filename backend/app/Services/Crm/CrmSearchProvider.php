<?php

namespace App\Services\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Services\Search\SearchProvider;

/** Búsqueda global de clientes, leads y oportunidades con el alcance de Ventas (§12-10, CRM-BR-33). */
class CrmSearchProvider implements SearchProvider
{
    public function __construct(private readonly CrmAccess $access) {}

    public function search(User $user, string $term, int $limit): array
    {
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $hits = [];

        foreach ($this->access->clients(Client::query(), $user)
            ->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like])->orWhereRaw('doc_number like ?', [$like])->orWhereRaw('email_norm like ?', [$like]))
            ->limit($limit)->get(['id', 'code', 'name', 'doc_type', 'doc_number']) as $c) {
            $hits[] = ['type' => 'client', 'id' => $c->id, 'code' => $c->code, 'title' => $c->name, 'subtitle' => "{$c->doc_type} {$c->doc_number}", 'route' => "crm/clientes/{$c->code}"];
        }

        foreach ($this->access->leads(Lead::query(), $user)
            ->where(fn ($q) => $q->whereRaw('lower(full_name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like])->orWhereRaw('email_norm like ?', [$like])->orWhereRaw('lower(company) like ?', [$like]))
            ->limit($limit)->get(['id', 'code', 'full_name', 'status']) as $l) {
            $hits[] = ['type' => 'lead', 'id' => $l->id, 'code' => $l->code, 'title' => $l->full_name, 'subtitle' => Lead::STATUS_LABELS[$l->status], 'route' => "crm/leads/{$l->code}"];
        }

        foreach ($this->access->opportunities(Opportunity::query(), $user)
            ->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like]))
            ->limit($limit)->get(['id', 'code', 'title', 'stage']) as $o) {
            $hits[] = ['type' => 'opportunity', 'id' => $o->id, 'code' => $o->code, 'title' => $o->title, 'subtitle' => $o->stage, 'route' => "crm/oportunidades/{$o->code}"];
        }

        return $hits;
    }
}
