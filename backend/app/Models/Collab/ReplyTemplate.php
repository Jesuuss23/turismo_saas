<?php

namespace App\Models\Collab;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Plantilla de respuesta con variables (spec 10 §3.9). */
class ReplyTemplate extends TenantModel
{
    use SoftDeletes;

    protected $table = 'collab_reply_templates';

    protected function casts(): array
    {
        return ['channels' => 'array', 'is_whatsapp_approved' => 'boolean', 'is_active' => 'boolean'];
    }
}
