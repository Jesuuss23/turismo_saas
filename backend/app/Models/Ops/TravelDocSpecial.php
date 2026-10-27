<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

/** Atención especial del pasajero: solo indicador y nota operativa, sin datos clínicos (spec 11 §3.8, DOC-BR-009). */
class TravelDocSpecial extends TenantModel
{
    protected $table = 'ops_travel_doc_special';

    protected function casts(): array
    {
        return ['requires_attention' => 'boolean'];
    }
}
