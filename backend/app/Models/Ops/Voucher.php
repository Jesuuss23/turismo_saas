<?php

namespace App\Models\Ops;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Voucher (§3.10, §4.4): un vigente por servicio, versionado con snapshot. */
class Voucher extends TenantModel
{
    use OptimisticLock;

    public const ISSUED = 'emitido';

    public const USED = 'utilizado';

    public const VOIDED = 'anulado';

    public const STATUS_LABELS = [self::ISSUED => 'Emitido', self::USED => 'Utilizado', self::VOIDED => 'Anulado'];

    protected $table = 'ops_vouchers';

    protected function casts(): array
    {
        return ['service_date' => 'date', 'issued_at' => 'datetime', 'used_at' => 'datetime', 'void_at' => 'datetime', 'version' => 'integer', 'pax' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'reservation_service_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(VoucherVersion::class)->orderByDesc('version');
    }
}
