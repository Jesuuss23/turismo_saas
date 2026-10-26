<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hold de disponibilidad (§4.5, PROC-020). */
class Hold extends TenantModel
{
    use OptimisticLock;

    public const TERMINAL = ['liberada', 'expirada', 'consumida'];

    protected $table = 'catalog_availability_holds';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'quantity' => 'integer',
        ];
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class, 'departure_id');
    }
}
