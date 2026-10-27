<?php

namespace App\Models\Ops;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Habitación de la rooming list (§3.9); capacidad derivada del tipo (OPS-BR-32). */
class RoomingRoom extends TenantModel
{
    public const CAPACITY = ['Simple' => 1, 'Doble' => 2, 'Doble twin' => 2, 'Matrimonial' => 2, 'Triple' => 3, 'Junior suite' => 2];

    public const STATUSES = ['confirmada', 'pendiente', 'observada'];

    protected $table = 'ops_rooming_rooms';

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function guests(): HasMany
    {
        return $this->hasMany(RoomingGuest::class, 'room_id');
    }
}
