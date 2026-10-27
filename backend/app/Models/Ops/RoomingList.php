<?php

namespace App\Models\Ops;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Rooming list por reserva y hotel (§3.8, §4.6). */
class RoomingList extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const DRAFT = 'borrador';

    public const CONFIRMED = 'confirmada';

    public const MODIFIED = 'modificada';

    public const SENT = 'enviada';

    public const STATUS_LABELS = [self::DRAFT => 'Borrador', self::CONFIRMED => 'Confirmada', self::MODIFIED => 'Modificada', self::SENT => 'Enviada'];

    protected $table = 'ops_rooming_lists';

    protected array $generated = ['stay'];

    protected function casts(): array
    {
        return ['check_in' => 'date', 'check_out' => 'date', 'sent_at' => 'datetime', 'hotel_ack_at' => 'datetime', 'hotel_confirmed_at' => 'datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'hotel_provider_id')->withTrashed();
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(RoomingRoom::class)->orderBy('room_number');
    }

    /** Rango `[check_in, check_out)` en literal daterange de PostgreSQL. */
    public function stayLiteral(): string
    {
        return '['.$this->check_in->toDateString().','.$this->check_out->toDateString().')';
    }
}
