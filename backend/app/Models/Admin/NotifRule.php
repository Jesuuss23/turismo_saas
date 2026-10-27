<?php

namespace App\Models\Admin;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Regla evento × canal (spec 09 §3.10). */
class NotifRule extends Model
{
    use BelongsToTenant, HasUuids;

    public const CREATED_AT = null;

    public const CHANNELS = ['email' => 'ch_email', 'whatsapp' => 'ch_whatsapp', 'push' => 'ch_push', 'portal' => 'ch_portal'];

    protected $table = 'notif_event_rules';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['ch_email' => 'boolean', 'ch_whatsapp' => 'boolean', 'ch_push' => 'boolean', 'ch_portal' => 'boolean'];
    }

    /** @return list<string> */
    public function activeChannels(): array
    {
        return array_keys(array_filter(self::CHANNELS, fn ($col) => $this->{$col}));
    }
}
