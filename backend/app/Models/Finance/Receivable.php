<?php

namespace App\Models\Finance;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Client;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cuenta por cobrar (PROC-040/041). Reservas crea anticipo/saldo, ajustes y penalidades; Finanzas solicita anticipos y cobra. */
class Receivable extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const OPEN = ['abierta', 'parcial', 'vencida'];

    public const STATUS_LABELS = ['abierta' => 'Abierta', 'parcial' => 'Parcial', 'vencida' => 'Vencida', 'pagada' => 'Pagada', 'cancelada' => 'Cancelada'];

    public const KIND_LABELS = ['anticipo' => 'Anticipo', 'saldo' => 'Saldo', 'cargo' => 'Cargo', 'ajuste' => 'Ajuste', 'penalidad' => 'Penalidad', 'b2b' => 'B2B'];

    protected $table = 'fin_receivables';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'due_date' => 'date', 'cancelled_at' => 'datetime', 'last_reminder_at' => 'datetime'];
    }

    public function balance(): string
    {
        return number_format(max(0, (float) $this->amount - (float) $this->paid_amount), 2, '.', '');
    }

    /** Tipo de la CxC por su concepto (las de Reservas no lo traen explícito; §3.1). */
    public static function kindFor(string $concept): string
    {
        $c = mb_strtolower($concept);

        return match (true) {
            str_starts_with($c, 'anticipo') => 'anticipo',
            str_starts_with($c, 'penalidad') => 'penalidad',
            str_contains($c, 'ajuste') || str_contains($c, 'modificación') || str_contains($c, 'reprogramación') => 'ajuste',
            default => 'saldo',
        };
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ReceivableReminder::class);
    }
}
