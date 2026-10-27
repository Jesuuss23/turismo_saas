<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** `fin:payment-reminders` (spec 07 §8.2): Recordatorios diarios de pago. */
class SendPaymentReminders extends Command
{
    protected $signature = 'fin:payment-reminders';

    protected $description = 'Finanzas: Recordatorios diarios de pago';

    public function handle(): int
    {
        return $this->call('fin:run', ['job' => 'payment-reminders']);
    }
}
