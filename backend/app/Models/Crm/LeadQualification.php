<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial BANT (spec 01 §3.3). Solo INSERT: cada calificación es una fila nueva. */
class LeadQualification extends CrmModel
{
    public const AUDIT_COLUMNS = [];

    public const LEVELS = [0, 10, 18, 25];

    public const LABELS = [
        'budget' => ['No definido', 'Referencial/bajo', 'Definido y acorde', 'Aprobado y disponible'],
        'authority' => ['Desconocida', 'Influenciador', 'Co-decisor', 'Decisor final'],
        'need' => ['Exploratoria', 'Interés general', 'Necesidad concreta', 'Urgente y específica'],
        'timeline' => ['Sin fecha', '> 6 meses', '1–6 meses', '< 30 días'],
    ];

    public $timestamps = false;

    protected $table = 'crm_lead_qualifications';

    protected array $generated = ['score'];

    protected function casts(): array
    {
        return ['qualified_at' => 'datetime', 'declared_amount' => 'decimal:2', 'score' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
