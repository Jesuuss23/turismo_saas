<?php

namespace App\Models\Finance;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Cuenta por cobrar (módulo Finanzas; Reservas crea anticipo 40 % / saldo 60 %, ajustes y penalidades). */
class Receivable extends TenantModel
{
    use OptimisticLock;

    public const OPEN = ['abierta', 'parcial', 'vencida'];

    protected $table = 'fin_receivables';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'due_date' => 'date'];
    }

    public function balance(): string
    {
        return number_format(max(0, (float) $this->amount - (float) $this->paid_amount), 2, '.', '');
    }
}
