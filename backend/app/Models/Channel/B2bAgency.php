<?php

namespace App\Models\Channel;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;

/** Agencia B2B con línea de crédito y % de convenio (base mínima de Canales; spec 07 §3.20, FIN-BR-10). */
class B2bAgency extends TenantModel
{
    use OptimisticLock;

    protected $table = 'channel_b2b_agencies';

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'credit_used' => 'decimal:2', 'commission_pct' => 'decimal:2'];
    }

    public function available(): string
    {
        return number_format(max(0, (float) $this->credit_limit - (float) $this->credit_used), 2, '.', '');
    }
}
