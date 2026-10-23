<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Spec 01 §3.2 y máquina de estados §4.1. */
class Lead extends CrmModel
{
    use OptimisticLock, SoftDeletes;

    public const NEW = 'nuevo';

    public const CONTACTED = 'contactado';

    public const QUALIFIED = 'calificado';

    public const CONVERTED = 'convertido';

    public const DUPLICATE = 'duplicado';

    public const DISCARDED = 'descartado';

    /** Estados en los que el lead sigue abierto (gestionable). */
    public const OPEN = [self::NEW, self::CONTACTED, self::QUALIFIED];

    public const STATUS_LABELS = [
        'nuevo' => 'Nuevo', 'contactado' => 'Contactado', 'calificado' => 'Calificado',
        'convertido' => 'Convertido', 'duplicado' => 'Duplicado', 'descartado' => 'Descartado',
    ];

    /** Umbral de calificación (CRM-BR-10). */
    public const QUALIFY_THRESHOLD = 50;

    protected $table = 'crm_leads';

    protected array $generated = ['email_norm', 'phone_norm'];

    protected function casts(): array
    {
        return [
            'travel_date' => 'date',
            'validated_at' => 'datetime',
            'assigned_at' => 'datetime',
            'first_contact_at' => 'datetime',
            'discarded_at' => 'datetime',
            'converted_at' => 'datetime',
            'pax' => 'integer',
            'score' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(LeadQualification::class)->orderByDesc('qualified_at');
    }

    public function currentQualification(): HasOne
    {
        return $this->hasOne(LeadQualification::class)->latest('qualified_at');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function convertedOpportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'converted_opportunity_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Paso actual del stepper de 8 pasos (§4.1): [paso, fallido]. */
    public function flowStep(): array
    {
        return match (true) {
            $this->status === self::CONVERTED => [8, false],
            $this->status === self::DUPLICATE => [3, true],
            $this->status === self::DISCARDED => [$this->discarded_at_step ?? 6, true],
            $this->owner_id === null => [5, false],
            $this->status === self::NEW => [6, false],
            $this->status === self::CONTACTED => [7, false],
            default => [8, false],
        };
    }
}
