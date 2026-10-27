<?php

namespace App\Models\Crm;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caso de postventa: reclamo o feedback (spec 01 §3.10, §4.4). */
class AfterSalesCase extends CrmModel
{
    use OptimisticLock;

    public const OPEN = 'abierta';

    public const IN_PROGRESS = 'en_proceso';

    public const RESOLVED = 'resuelta';

    public const CLOSED = 'cerrada';

    protected $table = 'crm_cases';

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'closed_at' => 'datetime', 'is_book' => 'boolean', 'consumer' => 'encrypted:array', 'good' => 'array',
            'response' => 'array', 'received_at' => 'datetime', 'due_date' => 'date', 'ack_sent_at' => 'datetime', 'response_days' => 'integer'];
    }

    /** Spec 12 LR-BR-08: en una hoja del libro el detalle, el pedido, el consumidor y la recepción son inmutables y no hay borrado. */
    public const BOOK_IMMUTABLE = ['detail', 'request', 'consumer', 'received_at', 'due_date', 'book_number', 'sede_id', 'response_days', 'good', 'type_code'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $case) {
            if ($case->getOriginal('is_book') && $case->isDirty(self::BOOK_IMMUTABLE)) {
                throw ApiException::rule('LR-BR-08', 'Los datos de la hoja de reclamación son inmutables.');
            }
        });
        static::deleting(function (self $case) {
            if ($case->is_book) {
                throw ApiException::rule('LR-BR-08', 'Las hojas del libro de reclamaciones no se eliminan.');
            }
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id')->orderBy('created_at');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::OPEN, self::IN_PROGRESS], true);
    }
}
