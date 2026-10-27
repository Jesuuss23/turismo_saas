<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Recordatorio de pago enviado (FIN-BR-05). */
class ReceivableReminder extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'fin_receivable_reminders';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'balance_at_send' => 'decimal:2'];
    }
}
