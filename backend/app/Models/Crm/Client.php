<?php

namespace App\Models\Crm;

use App\Models\Iam\User;
use App\Support\Concerns\OptimisticLock;
use App\Support\Database\PgArray;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Maestro único de clientes persona/empresa (spec 01 §3.4, §11-9). */
class Client extends CrmModel
{
    use OptimisticLock, SoftDeletes;

    protected $table = 'crm_clients';

    protected array $generated = ['email_norm', 'phone_norm'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'nps_last' => 'integer'];
    }

    protected function tags(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => PgArray::decode($value), set: fn (array $value) => PgArray::encode($value));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderBy('created_at');
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(ClientContact::class)->where('is_primary', true);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
