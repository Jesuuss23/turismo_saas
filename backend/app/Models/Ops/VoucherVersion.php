<?php

namespace App\Models\Ops;

use App\Models\TenantModel;

class VoucherVersion extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'ops_voucher_versions';

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'snapshot' => 'array', 'version' => 'integer'];
    }
}
