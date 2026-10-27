<?php

namespace App\Models\Treasury;

use App\Models\Finance\BankAccount;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cuenta de tesorería: banco, caja chica por sede o pasarela de pagos (spec 12 §3.1). El saldo se deriva de los movimientos. */
class Account extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const KINDS = ['bank' => 'Banco', 'petty_cash' => 'Caja chica', 'gateway' => 'Pasarela'];

    protected $table = 'fin_treasury_accounts';

    protected function casts(): array
    {
        return ['fund_amount' => 'decimal:2', 'opening_balance' => 'decimal:2', 'opening_rate' => 'decimal:6', 'opening_date' => 'date',
            'collect_methods' => 'array'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function settleTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settle_to_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function isPetty(): bool
    {
        return $this->kind === 'petty_cash';
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
