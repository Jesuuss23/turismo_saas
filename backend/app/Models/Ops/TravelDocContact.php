<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

/** Contacto de emergencia del pasajero (spec 11 §3.8, DOC-BR-008). */
class TravelDocContact extends TenantModel
{
    public const RELATIONS = ['Familiar', 'Pareja', 'Amigo(a)', 'Empresa', 'Otro'];

    protected $table = 'ops_travel_doc_contacts';
}
