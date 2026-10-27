<?php

namespace App\Services\Platform;

use App\Exceptions\ApiException;
use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cupones (spec 13 §3.9, SUB-BR-07/08): activo, vigente, con canjes disponibles, aplicable al plan y no acumulable.
 * El canje bloquea la fila del cupón (`lockForUpdate`): de dos canjes simultáneos del último cupo solo prospera uno.
 * El descuento se aplica a las facturas de renovación durante `duration_cycles` ciclos.
 */
class CouponService
{
    public function __construct(private readonly PlatformEvents $events) {}

    /** Canje del Tenant Admin (o del Super Admin al convertir un trial). */
    public function redeem(object $sub, string $code, User|PlatformUser|null $actor): object
    {
        $code = Str::upper(trim($code));
        $db = self::db();
        try {
            $redemption = $db->transaction(function (ConnectionInterface $db) use ($sub, $code) {
                $coupon = $db->table('coupons')->where('code', $code)->lockForUpdate()->first();
                $this->assertRedeemable($coupon, $sub, $code);
                $id = (string) Str::uuid7();
                $db->table('coupon_redemptions')->insert(['id' => $id, 'coupon_id' => $coupon->id, 'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id,
                    'remaining_cycles' => $coupon->duration_cycles, 'created_at' => now()]);
                $db->table('coupons')->where('id', $coupon->id)->update(['redeemed_count' => $coupon->redeemed_count + 1]);
                $db->table('subscriptions')->where('id', $sub->id)->update(['coupon_redemption_id' => $id, 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);

                return $coupon;
            });
        } catch (ApiException $e) {
            $this->events->audit($sub->tenant_id, 'Suscripción', $sub->code, 'cupon_rechazado', null, "{$code} · {$e->getMessage()}", $actor, $actor === null);

            throw $e;
        }
        $this->events->audit($sub->tenant_id, 'Suscripción', $sub->code, 'cupon_aplicar', null, "{$code} · ".self::describe($redemption), $actor, $actor === null);

        return $redemption;
    }

    /** SUB-BR-07: 422 COUPON_INVALID con el motivo. */
    private function assertRedeemable(?object $coupon, object $sub, string $code): void
    {
        $fail = fn (string $why) => throw new ApiException(422, 'COUPON_INVALID', 'SUB-BR-07', $why, [], ['code' => $code]);
        if (! $coupon || $coupon->status !== 'active') {
            $fail("El cupón {$code} no existe o no está activo.");
        }
        if ($coupon->valid_to < PlatformClock::today()->toDateString()) {
            $fail("El cupón {$code} venció el ".date('d/m/Y', strtotime($coupon->valid_to)).'.');
        }
        if ($coupon->redeemed_count >= $coupon->max_redemptions) {
            $fail("El cupón {$code} ya no tiene canjes disponibles.");
        }
        $plans = json_decode($coupon->plan_ids, true) ?: [];
        if ($plans !== [] && ! in_array($sub->plan_id, $plans, true)) {
            $fail("El cupón {$code} no aplica a su plan.");
        }
        $db = self::db();
        if ($db->table('coupon_redemptions')->where('coupon_id', $coupon->id)->where('tenant_id', $sub->tenant_id)->exists()) {
            $fail("Su cuenta ya canjeó el cupón {$code}.");
        }
        if ($sub->coupon_redemption_id && (int) $db->table('coupon_redemptions')->where('id', $sub->coupon_redemption_id)->value('remaining_cycles') > 0) {
            $fail('Los cupones no son acumulables: ya tiene un descuento vigente.');
        }
    }

    /** Cupón vigente de la suscripción (con ciclos restantes) o null. */
    public function active(object $sub): ?object
    {
        if (! $sub->coupon_redemption_id) {
            return null;
        }

        return self::db()->table('coupon_redemptions as r')->join('coupons as c', 'c.id', '=', 'r.coupon_id')->where('r.id', $sub->coupon_redemption_id)
            ->where('r.remaining_cycles', '>', 0)->first(['r.id as redemption_id', 'r.remaining_cycles', 'c.code', 'c.type', 'c.value', 'c.duration_cycles']);
    }

    /** Descuento sobre una base sin IGV. */
    public static function discount(object $coupon, string $base): string
    {
        $amount = $coupon->type === 'percent'
            ? BigDecimal::of($base)->multipliedBy((string) $coupon->value)->dividedBy(100, 2, RoundingMode::HalfUp)
            : BigDecimal::of((string) $coupon->value)->toScale(2);

        return (string) BigDecimal::min($amount, BigDecimal::of($base)->toScale(2));
    }

    /** Consume un ciclo del cupón (renovación facturada). */
    public function consume(object $coupon): void
    {
        self::db()->table('coupon_redemptions')->where('id', $coupon->redemption_id)->where('remaining_cycles', '>', 0)->decrement('remaining_cycles');
    }

    public static function describe(object $coupon): string
    {
        $value = $coupon->type === 'percent' ? rtrim(rtrim((string) $coupon->value, '0'), '.').' %' : "S/ {$coupon->value}";

        return "{$value} por {$coupon->duration_cycles} ciclo(s)";
    }

    // ───────────────────────────── Consola (Super Admin) ─────────────────────────────

    public function list(): array
    {
        $plans = self::db()->table('plans')->pluck('name', 'id');

        return self::db()->table('coupons')->orderByDesc('created_at')->get()->map(fn ($c) => [
            'id' => $c->id, 'code' => $c->code, 'type' => $c->type, 'value' => (float) $c->value, 'duration_cycles' => (int) $c->duration_cycles,
            'plans' => array_map(fn ($p) => $plans[$p] ?? $p, json_decode($c->plan_ids, true) ?: []), 'plan_ids' => json_decode($c->plan_ids, true) ?: [],
            'max_redemptions' => (int) $c->max_redemptions, 'redeemed_count' => (int) $c->redeemed_count, 'valid_to' => $c->valid_to, 'status' => $c->status,
            'expired' => $c->valid_to < PlatformClock::today()->toDateString(), 'note' => $c->note, 'label' => self::describe($c),
        ])->all();
    }

    public function create(array $data, PlatformUser $actor): array
    {
        $code = Str::upper(trim((string) ($data['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9_-]{4,20}$/', $code)) {
            throw ApiException::invalid('code', 'Use de 4 a 20 caracteres: mayúsculas, números, guion o guion bajo.');
        }
        $type = $data['type'] ?? 'percent';
        $value = (float) ($data['value'] ?? 0);
        if (! in_array($type, ['percent', 'fixed'], true)) {
            throw ApiException::invalid('type', 'Tipo de descuento inválido.');
        }
        if ($value <= 0) {
            throw ApiException::invalid('value', 'El descuento debe ser mayor que cero.');
        }
        if ($type === 'percent' && $value > 50) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'SUB-BR-08', 'SUB-BR-08: el descuento porcentual máximo es 50 %.', [], ['errors' => ['value' => ['SUB-BR-08: el descuento porcentual máximo es 50 %.']]]);
        }
        $cycles = (int) ($data['duration_cycles'] ?? 1);
        $max = (int) ($data['max_redemptions'] ?? 0);
        if ($cycles < 1 || $cycles > 12) {
            throw ApiException::invalid('duration_cycles', 'La duración va de 1 a 12 ciclos.');
        }
        if ($max < 1) {
            throw ApiException::invalid('max_redemptions', 'Indique el máximo de canjes.');
        }
        $validTo = (string) ($data['valid_to'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $validTo) || $validTo < PlatformClock::today()->toDateString()) {
            throw ApiException::invalid('valid_to', 'La vigencia debe ser una fecha futura.');
        }
        $db = self::db();
        if ($db->table('coupons')->where('code', $code)->exists()) {
            throw ApiException::invalid('code', "El cupón {$code} ya existe.");
        }
        $plans = array_values(array_intersect((array) ($data['plan_ids'] ?? []), $db->table('plans')->pluck('id')->all()));
        $id = (string) Str::uuid7();
        $db->table('coupons')->insert(['id' => $id, 'code' => $code, 'type' => $type, 'value' => $value, 'duration_cycles' => $cycles, 'plan_ids' => json_encode($plans),
            'max_redemptions' => $max, 'redeemed_count' => 0, 'valid_to' => $validTo, 'status' => 'active', 'note' => $data['note'] ?? null, 'created_by' => $actor->id,
            'created_at' => now()]);
        $this->auditPlatform($actor, $code, 'crear', null, self::describe((object) ['type' => $type, 'value' => $value, 'duration_cycles' => $cycles])." · {$max} canje(s)");

        return collect($this->list())->firstWhere('id', $id);
    }

    public function setStatus(string $id, string $status, PlatformUser $actor): array
    {
        $coupon = self::db()->table('coupons')->where('id', $id)->first() ?? throw ApiException::notFound();
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ApiException::invalid('status', 'Estado inválido.');
        }
        self::db()->table('coupons')->where('id', $id)->update(['status' => $status]);
        $this->auditPlatform($actor, $coupon->code, $status === 'active' ? 'activar' : 'desactivar', $coupon->status === 'active' ? 'Activo' : 'Inactivo',
            $status === 'active' ? 'Activo' : 'Inactivo');

        return collect($this->list())->firstWhere('id', $id);
    }

    private function auditPlatform(PlatformUser $actor, string $code, string $action, ?string $before, ?string $after): void
    {
        if ($actor->home_tenant_id) {
            $this->events->audit($actor->home_tenant_id, 'Cupón', $code, $action, $before, $after, $actor);
        }
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
