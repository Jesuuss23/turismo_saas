<?php

namespace App\Models\Collab;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mensaje de una conversación (spec 10 §3.7): `internal` = nota interna, nunca se envía (INB-BR-10). */
class Message extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'collab_messages';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'message_id');
    }
}
