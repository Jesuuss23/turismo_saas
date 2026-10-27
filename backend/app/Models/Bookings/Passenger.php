<?php

namespace App\Models\Bookings;

use App\Models\TenantModel;

/** Pasajero de la reserva; nace como ficha "por completar" (§4.3 paso 13). */
class Passenger extends TenantModel
{
    public const TYPES = ['ADT', 'CHD', 'INF'];

    public const DOC_TYPES = ['DNI', 'Pasaporte', 'CE', 'Passport'];

    public const RELATIONSHIPS = ['Titular', 'Cónyuge / pareja', 'Hijo/a', 'Padre / madre', 'Hermano/a', 'Amigo/a', 'Otro'];

    protected $table = 'booking_passengers';

    protected function casts(): array
    {
        return ['seq' => 'integer', 'age' => 'integer', 'birth_date' => 'date', 'is_lead' => 'boolean', 'is_placeholder' => 'boolean'];
    }
}
