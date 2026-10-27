<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class PlanChannel extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'channel_key';

    protected $keyType = 'string';

    protected $guarded = [];
}
