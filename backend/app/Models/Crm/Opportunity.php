<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Spec 01 §3.7 y máquina de estados §4.2. */
class Opportunity extends CrmModel
{
    use OptimisticLock, SoftDeletes;

    public const WON = 'ganada';

    public const LOST = 'perdida';

    public const CLOSED = [self::WON, self::LOST];

    public const OPEN_STAGES = ['calificacion', 'descubrimiento', 'cotizacion', 'negociacion'];

    protected $table = 'crm_opportunities';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'probability' => 'integer',
            'pax' => 'integer',
            'expected_close_date' => 'date',
            'closed_at' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->stage, self::CLOSED, true);
    }
}
