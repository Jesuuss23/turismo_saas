<?php

namespace App\Models\Channel;

use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Contenido del CMS (spec 08 §3.10, PROC-055). */
class CmsContent extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const TYPES = ['Página', 'Artículo', 'Banner', 'Página legal', 'Landing'];

    public const CHANNELS = ['Web', 'Web / E-commerce', 'Web / Portal', 'Portal cliente', 'Portal pasajero', 'App pasajero'];

    public const STATUS_LABELS = ['borrador' => 'Borrador', 'en_revision' => 'En revisión', 'aprobado' => 'Aprobado', 'publicado' => 'Publicado', 'despublicado' => 'Despublicado'];

    /** CMS-BR-02: estados que cualquier redactor puede guardar. */
    public const FREE_EDIT = ['borrador', 'despublicado'];

    protected $table = 'cms_contents';

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'unpublished_at' => 'datetime', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CmsContentEvent::class, 'content_id')->orderByDesc('occurred_at');
    }
}
