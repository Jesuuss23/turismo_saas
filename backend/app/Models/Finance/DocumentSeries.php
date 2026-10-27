<?php

namespace App\Models\Finance;

use App\Models\TenantModel;

/** Serie de comprobantes con su correlativo (FIN-BR-46). */
class DocumentSeries extends TenantModel
{
    protected $table = 'fin_document_series';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
