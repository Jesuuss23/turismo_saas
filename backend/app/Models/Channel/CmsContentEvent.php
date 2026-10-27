<?php

namespace App\Models\Channel;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora editorial (spec 08 §3.11, CMS-BR-06). */
class CmsContentEvent extends TenantModel
{
    public $timestamps = false;

    public const AUDIT_COLUMNS = [];

    protected $table = 'cms_content_events';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
