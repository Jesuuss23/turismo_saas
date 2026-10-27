<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}
