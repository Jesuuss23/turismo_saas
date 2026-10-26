<?php

namespace App\Services\Quotes;

use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\Search\SearchProvider;

/** Búsqueda global de cotizaciones por código, título o cliente; Ventas solo las propias (QTE-BR-26). */
class QuoteSearchProvider implements SearchProvider
{
    public function __construct(private readonly QuoteAccess $access) {}

    public function search(User $user, string $term, int $limit): array
    {
        if (! $user->hasPermissionTo(QuoteAccess::VIEW) || $user->isExternal()) {
            return [];
        }
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        return $this->access->scope(Quote::query(), $user)
            ->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(title) like ?', [$like])
                ->orWhereHas('client', fn ($c) => $c->whereRaw('lower(name) like ?', [$like])))
            ->with('client:id,name')->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn (Quote $q) => ['type' => 'quote', 'id' => $q->id, 'code' => $q->code, 'title' => $q->title,
                'subtitle' => ($q->client?->name ?? '').' · '.Quote::STATUS_LABELS[$q->status], 'route' => "cotizaciones/{$q->code}"])->all();
    }
}
