<?php

namespace App\Models\Ops;

use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ítem del checklist documental de un pasajero (spec 11 §3.7). `vencido` y `no_aplica` se calculan al leer. */
class TravelDocItem extends TenantModel
{
    use OptimisticLock;

    public const TYPES = [
        'id_doc' => 'Documento de identidad', 'visa' => 'Visa', 'insurance' => 'Seguro de viaje', 'yellow_fever' => 'Vacuna fiebre amarilla',
        'minor_auth' => 'Autorización de menor', 'migration' => 'Registro migratorio (TAM)', 'emergency' => 'Contacto de emergencia', 'special' => 'Atención especial',
    ];

    public const STATUS_LABELS = [
        'pendiente' => 'Pendiente', 'recibido' => 'Recibido', 'completo' => 'Completo', 'observado' => 'Observado', 'vencido' => 'Vencido', 'no_aplica' => 'No aplica',
    ];

    /** Tipos que se completan con datos (contacto, atención especial) y no con archivo. */
    public const DATA_TYPES = ['emergency', 'special'];

    protected $table = 'ops_travel_doc_items';

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'validated_at' => 'datetime', 'uploaded_at' => 'datetime', 'file_size' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
