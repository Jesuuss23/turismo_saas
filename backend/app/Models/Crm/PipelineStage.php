<?php

namespace App\Models\Crm;

class PipelineStage extends CrmModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'crm_pipeline_stages';

    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }
}
