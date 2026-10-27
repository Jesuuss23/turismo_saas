<?php

namespace App\Models\Marketing;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Plantilla de email o WhatsApp (spec 14 §3.5); las de WhatsApp pasan por la aprobación de Meta (MKT-BR-03). */
class Template extends TenantModel
{
    use OptimisticLock;

    public const STATUS_LABELS = ['activa' => 'Activa', 'en_revision' => 'En revisión Meta', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada'];

    public const VARIABLES = ['nombre', 'destino', 'asesor', 'codigo_promo', 'fecha_viaje', 'agencia', 'enlace_baja'];

    public function usable(): bool
    {
        return $this->channel === 'email' || $this->status === 'aprobada';
    }

    protected $table = 'mkt_templates';

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
