<?php

namespace App\Models\Sys;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotifMessage extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'notif_messages';

    protected $guarded = ['id', 'code', 'tenant_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'sent_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function displayCode(): string
    {
        return 'NTF-'.$this->code;
    }
}
