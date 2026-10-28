<?php

namespace App\Services\Catalog\Booking;

use App\Domain\Bookings\AvailabilityCache;
use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\PaxRulesResolver;
use App\Domain\Bookings\QuoteSelection;
use App\Domain\Bookings\RuleCatalog;
use App\Domain\Bookings\RuleResolver;
use App\Events\Catalog\BookingRuleChanged;
use App\Exceptions\ApiException;
use App\Models\Catalog\AccommodationHotel;
use App\Models\Catalog\AccommodationLeg;
use App\Models\Catalog\AccommodationRoomType;
use App\Models\Catalog\AvailabilityRange;
use App\Models\Catalog\PaxRule;
use App\Models\Catalog\PaymentPolicy;
use App\Models\Catalog\PickupPoint;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAddon;
use App\Models\Catalog\Provider;
use App\Models\Catalog\RuleOverride;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogAccess;
use App\Services\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Configuración comercial del producto para el motor (spec 16 §2, pestaña «Reservas online» del Product 360):
 * pasajeros, adicionales, alojamiento, pago, recojo y overrides de reglas, con las validaciones CAT-* y la auditoría §9.
 * Apagar una regla nunca borra la configuración guardada.
 */
class BookingConfigService
{
    /** Sub-sección => reglas que la gobiernan (si todas están apagadas, la sección se rotula «(desactivada)»). */
    public const SECTIONS = [
        'disponibilidad' => ['R-AVAIL-RANGES'],
        'pasajeros' => ['R-CHILD-PER-ADULT', 'R-INFANT-FREE'],
        'adicionales' => ['R-ADDONS'],
        'alojamiento' => ['R-LODGING'],
        'pago' => ['R-DEPOSIT', 'R-PAY-LATER'],
        'recojo' => ['R-PICKUP'],
        'reglas' => [],
    ];

    public const PAX_FIELDS = ['adult_min_age', 'child_min_age', 'child_max_age', 'infant_max_age', 'child_pays', 'child_price_pct', 'infant_allowed', 'infant_pays',
        'max_children_per_adult', 'max_infants_per_adult', 'min_adults', 'note'];

    public function __construct(
        private readonly BookingEngine $engine,
        private readonly RuleResolver $rules,
        private readonly PaxRulesResolver $pax,
        private readonly AvailabilityCache $cache,
        private readonly CatalogAccess $access,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    // --- resumen (GET booking-config) ---

    public function summary(Product $p, User $user): array
    {
        $today = $this->engine->today();
        $to = CarbonImmutable::parse($today)->addDays(120)->toDateString();
        $rangeRows = AvailabilityRange::where('product_id', $p->id)->where('date_to', '>=', $today)->orderBy('date_from')->orderBy('code')->get();

        return [
            'product' => ['id' => $p->id, 'ref' => $p->ref, 'code' => $p->code, 'name' => $p->name, 'status' => $p->status, 'duration_value' => (float) $p->duration_value,
                'duration_unit' => $p->duration_unit, 'max_pax' => $p->maximum_passengers, 'min_pax' => $p->minimum_passengers],
            'today' => $today,
            'sections' => $this->sections($p),
            'legend' => $this->engine->legend($p, $today, $to),
            'next_available' => $this->engine->nextAvailable($p, 1),
            'ranges' => $rangeRows->map(fn (AvailabilityRange $r) => $this->range($r))->all(),
            'operation' => $this->operation($p),
            'pax' => $this->paxConfig($p),
            'addons' => $this->addons($p),
            'accommodation' => $this->accommodation($p),
            'payment' => $this->payment($p),
            'pickup' => $this->pickup($p),
            'rules' => $this->overrides($p),
            'can' => $this->can($user),
        ];
    }

    public function can(User $user): array
    {
        return [
            'availability' => $this->access->can($user, CatalogAccess::AVAIL),
            'update' => $this->access->can($user, CatalogAccess::UPDATE),
            'rates' => $this->access->can($user, CatalogAccess::RATES),
            'pickup' => $this->access->can($user, CatalogAccess::UPDATE) || $this->access->can($user, CatalogAccess::AVAIL),
            'rules_center' => $user->hasPermissionTo('admin.settings.manage'),
        ];
    }

    /** @return array<string, array{state: string, rules: list<array>}> */
    public function sections(Product $p): array
    {
        $out = [];
        foreach (self::SECTIONS as $key => $keys) {
            $states = array_map(fn ($k) => ['key' => $k, 'name' => $this->rules->rule($k)['name'] ?? $k, 'state' => $this->rules->state($k, $p)], $keys);
            $all = array_column($states, 'state');
            $state = match (true) {
                $all === [] || in_array('on', $all, true) => 'on',
                ! array_diff($all, ['off_tenant']) => 'off_tenant',
                ! array_diff($all, ['off_tenant', 'off_plan']) => 'off_plan',
                default => 'off_product',
            };
            $out[$key] = ['state' => $state, 'rules' => $states];
        }

        return $out;
    }

    public function range(AvailabilityRange $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'date_from' => $r->date_from->toDateString(), 'date_to' => $r->date_to->toDateString(), 'status' => $r->status,
            'capacity_per_day' => $r->capacity_per_day, 'note' => $r->note, 'lock_version' => $r->lock_version];
    }

    public function operation(Product $p): array
    {
        return ['operating_weekdays' => array_map('intval', (array) $p->operating_weekdays), 'cutoff_hours' => $p->cutoff_hours, 'effective_cutoff_hours' => $this->engine->cutoffHours($p),
            'advance_min_days' => (int) $p->booking_advance_min_days, 'default_hours' => (int) $this->rules->param('R-CUTOFF', 'defaultHours', $p, 24)];
    }

    // --- pasajeros (§3.4) ---

    public function paxConfig(Product $p): array
    {
        $row = PaxRule::where('product_id', $p->id)->first();

        return ['effective' => $this->pax->resolve($p)->toArray(), 'stored' => $row?->only(self::PAX_FIELDS), 'defaults' => $this->pax->defaults(), 'warnings' => $row ? self::bandGaps($row->only(self::PAX_FIELDS)) : []];
    }

    /** @return array{pax: array, warnings: list<string>} */
    public function savePax(Product $p, array $data, User $actor): array
    {
        $current = PaxRule::where('product_id', $p->id)->first();
        $row = array_replace($current?->only(self::PAX_FIELDS) ?? $this->pax->defaults(), array_intersect_key($data, array_flip(self::PAX_FIELDS)));
        foreach (['adult_min_age', 'child_min_age', 'child_max_age', 'infant_max_age', 'max_children_per_adult', 'max_infants_per_adult', 'min_adults'] as $f) {
            if (! is_numeric($row[$f]) || (int) $row[$f] != $row[$f] || $row[$f] < 0 || $row[$f] > 120) {
                throw ApiException::invalid($f, 'Debe ser un número entero válido.');
            }
            $row[$f] = (int) $row[$f];
        }
        foreach (['child_pays', 'infant_allowed', 'infant_pays'] as $f) {
            $row[$f] = filter_var($row[$f], FILTER_VALIDATE_BOOLEAN);
        }
        if (! ($row['infant_max_age'] < $row['child_min_age'] && $row['child_min_age'] <= $row['child_max_age'] && $row['child_max_age'] < $row['adult_min_age'])) {
            throw ApiException::unprocessable('CAT-PX-01', 'CAT-PX-01: las bandas deben cumplir infante hasta < niño desde ≤ niño hasta < adulto desde.');
        }
        $pct = $row['child_price_pct'];
        if (! is_numeric($pct) || $pct < 0 || $pct > 100 || $row['max_children_per_adult'] > 10 || $row['max_infants_per_adult'] > 5 || $row['min_adults'] > 10) {
            throw ApiException::unprocessable('CAT-PX-02', 'CAT-PX-02: % niño 0–100; máximo 10 niños y 5 infantes por adulto; mínimo de adultos hasta 10.');
        }
        $row['child_price_pct'] = (string) $pct;
        $row['note'] = isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null;
        $before = $current ? self::paxLabel($current->only(self::PAX_FIELDS)) : 'hereda tenant';
        DB::transaction(function () use ($p, $row, $current, $before, $actor) {
            $current ? $current->fill($row)->save() : PaxRule::create($row + ['product_id' => $p->id]);
            $this->audit->log('Producto', $p->ref, 'reglas_pasajeros', $before, self::paxLabel($row), actor: $actor);
        });
        $this->changed($p);

        return ['pax' => $this->paxConfig($p), 'warnings' => self::bandGaps($row)];
    }

    public function resetPax(Product $p, User $actor): array
    {
        $current = PaxRule::where('product_id', $p->id)->first();
        if ($current) {
            DB::transaction(function () use ($p, $current, $actor) {
                $current->delete();
                $this->audit->log('Producto', $p->ref, 'reglas_pasajeros', self::paxLabel($current->only(self::PAX_FIELDS)), 'hereda tenant', actor: $actor);
            });
            $this->changed($p);
        }

        return $this->paxConfig($p);
    }

    /** CAT-PX-01: edades sin banda (brecha) → advertencia. @return list<string> */
    public static function bandGaps(array $r): array
    {
        $w = [];
        if ($r['child_min_age'] > $r['infant_max_age'] + 1) {
            $w[] = 'Edades '.($r['infant_max_age'] + 1).'–'.($r['child_min_age'] - 1).' sin banda: el checkout las rechazará.';
        }
        if ($r['adult_min_age'] > $r['child_max_age'] + 1) {
            $w[] = 'Edades '.($r['child_max_age'] + 1).'–'.($r['adult_min_age'] - 1).' sin banda: el checkout las rechazará.';
        }

        return $w;
    }

    public static function paxLabel(array $r): string
    {
        $child = $r['child_pays'] ? "paga {$r['child_price_pct']} %" : 'no paga';

        return "Adulto {$r['adult_min_age']}+ · niño {$r['child_min_age']}–{$r['child_max_age']} ({$child}) · infante 0–{$r['infant_max_age']} · máx. {$r['max_children_per_adult']} niño(s) y {$r['max_infants_per_adult']} infante(s) por adulto · mín. {$r['min_adults']} adulto(s)";
    }

    // --- adicionales (§3.5) ---

    public function addons(Product $p): array
    {
        $providers = Provider::withTrashed()->whereIn('id', ProductAddon::where('product_id', $p->id)->whereNotNull('provider_id')->pluck('provider_id'))->pluck('name', 'id');

        return ProductAddon::where('product_id', $p->id)->orderBy('code')->get()->map(fn (ProductAddon $a) => [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'category' => $a->category, 'price' => (string) $a->price, 'currency' => $a->currency,
            'charge_per' => $a->charge_per, 'applies_to' => $a->applies_to, 'stock_note' => $a->stock_note, 'provider_id' => $a->provider_id,
            'provider' => $a->provider_id ? ($providers[$a->provider_id] ?? null) : null, 'service_id' => $a->service_id, 'is_optional' => $a->is_optional,
        ])->all();
    }

    public function saveAddon(Product $p, array $data, User $actor, ?ProductAddon $addon = null): ProductAddon
    {
        $row = array_replace($addon?->only(['name', 'category', 'price', 'currency', 'charge_per', 'applies_to', 'stock_note', 'provider_id', 'service_id', 'is_optional'])
            ?? ['charge_per' => 'pax', 'applies_to' => ['ADT', 'CHD'], 'is_optional' => true, 'currency' => 'USD', 'category' => 'Otro'], array_intersect_key($data,
                array_flip(['name', 'category', 'price', 'currency', 'charge_per', 'applies_to', 'stock_note', 'provider_id', 'service_id', 'is_optional'])));
        $row['name'] = trim((string) ($row['name'] ?? ''));
        if ($row['name'] === '' || mb_strlen($row['name']) > 150) {
            throw ApiException::invalid('name', 'Indique el nombre del adicional (hasta 150 caracteres).');
        }
        if (! in_array($row['category'], ProductAddon::CATEGORIES, true)) {
            throw ApiException::invalid('category', 'Categoría inválida.');
        }
        if (! is_numeric($row['price'] ?? null) || $row['price'] < 0) {
            throw ApiException::unprocessable('CAT-AD-01', 'CAT-AD-01: el precio debe ser mayor o igual a 0.');
        }
        if (! in_array($row['currency'], ['PEN', 'USD'], true)) {
            throw ApiException::unprocessable('CAT-AD-01', 'CAT-AD-01: la moneda debe ser una moneda activa del tenant (PEN o USD).');
        }
        if (! in_array($row['charge_per'], ['pax', 'reserva'], true)) {
            throw ApiException::invalid('charge_per', 'El cobro es por pasajero o por reserva.');
        }
        $applies = array_values(array_intersect(['ADT', 'CHD', 'INF'], (array) $row['applies_to']));
        if ($applies === []) {
            throw ApiException::unprocessable('CAT-AD-01', 'CAT-AD-01: el adicional debe aplicar al menos a un tipo de pasajero.');
        }
        $row['applies_to'] = $applies;
        $dup = ProductAddon::where('product_id', $p->id)->whereRaw('lower(name) = ?', [mb_strtolower($row['name'])])->when($addon, fn ($q) => $q->whereKeyNot($addon->id))->exists();
        if ($dup) {
            throw ApiException::unprocessable('CAT-AD-01', "CAT-AD-01: ya existe un adicional «{$row['name']}» en este producto.");
        }
        // CAT-AD-02: servicio y proveedor deben coincidir; solo servicio ⇒ hereda su proveedor.
        $row['provider_id'] = ($row['provider_id'] ?? null) ?: null;
        $row['service_id'] = ($row['service_id'] ?? null) ?: null;
        if ($row['service_id']) {
            $service = Str::isUuid($row['service_id']) ? SupplierService::find($row['service_id']) : null;
            if (! $service) {
                throw ApiException::invalid('service_id', 'Servicio no encontrado.');
            }
            if ($row['provider_id'] && $service->provider_id !== $row['provider_id']) {
                throw ApiException::unprocessable('CAT-AD-02', 'CAT-AD-02: el servicio elegido no pertenece al proveedor.');
            }
            $row['provider_id'] = $service->provider_id;
        } elseif ($row['provider_id'] && (! Str::isUuid($row['provider_id']) || ! Provider::whereKey($row['provider_id'])->exists())) {
            throw ApiException::invalid('provider_id', 'Proveedor no encontrado.');
        }
        $row['stock_note'] = isset($row['stock_note']) && trim((string) $row['stock_note']) !== '' ? mb_substr(trim((string) $row['stock_note']), 0, 200) : null;
        $row['is_optional'] = filter_var($row['is_optional'], FILTER_VALIDATE_BOOLEAN);
        $row['price'] = (string) $row['price'];

        $saved = DB::transaction(function () use ($p, $row, $addon, $actor) {
            if ($addon) {
                $before = self::addonLabel($addon);
                $addon->fill($row)->save();
                $this->audit->log('Producto', $p->ref, 'adicional_modificar', $before, self::addonLabel($addon), actor: $actor);

                return $addon;
            }
            $created = ProductAddon::create($row + ['product_id' => $p->id, 'code' => $this->sequences->next('product_addon')]);
            $this->audit->log('Producto', $p->ref, 'adicional_crear', null, self::addonLabel($created), actor: $actor);

            return $created;
        });
        $this->changed($p);

        return $saved;
    }

    /** CAT-AD-03: soft delete; las reservas guardan su snapshot. */
    public function deleteAddon(Product $p, ProductAddon $addon, User $actor): void
    {
        DB::transaction(function () use ($p, $addon, $actor) {
            $addon->delete();
            $this->audit->log('Producto', $p->ref, 'adicional_eliminar', self::addonLabel($addon), null, actor: $actor);
        });
        $this->changed($p);
    }

    public static function addonLabel(ProductAddon $a): string
    {
        return "{$a->code} · {$a->name} · {$a->currency} {$a->price} por ".($a->charge_per === 'pax' ? 'pasajero' : 'reserva').' · aplica a '.implode('/', (array) $a->applies_to);
    }

    // --- alojamiento (§3.6) ---

    public function accommodation(Product $p): array
    {
        $legs = AccommodationLeg::where('product_id', $p->id)->with('hotels.roomTypes')->orderBy('sequence')->get();
        $nights = (int) $legs->sum('nights');
        $warnings = [];
        $expected = match ($p->duration_unit) {
            'días' => (int) $p->duration_value - 1,
            'noches' => (int) $p->duration_value,
            default => null,
        };
        if ($legs->isNotEmpty() && $expected !== null && $nights !== $expected) {
            $warnings[] = "CAT-LG-02: la suma de noches ({$nights}) no coincide con la duración del producto ({$expected} noche(s)).";
        }
        $cities = $legs->pluck('city')->map(fn ($c) => mb_strtolower($c));
        if ($cities->count() !== $cities->unique()->count()) {
            $warnings[] = 'Hay tramos repetidos en la misma ciudad.';
        }
        foreach ($legs as $leg) {
            if ($leg->hotels->isEmpty()) {
                $warnings[] = "{$leg->city}: sin hoteles configurados.";
            }
        }

        return [
            'enabled' => (bool) $p->accommodation_enabled, 'optional' => (bool) $p->accommodation_optional, 'currency' => $p->accommodation_currency, 'note' => $p->accommodation_note,
            'summary' => $legs->isEmpty() ? 'Sin tramos' : $legs->count().' ciudad(es) · '.$nights.' noche(s)', 'nights' => $nights, 'expected_nights' => $expected,
            'legs' => $legs->map(fn (AccommodationLeg $l) => [
                'id' => $l->id, 'code' => $l->code, 'sequence' => $l->sequence, 'city' => $l->city, 'destination_id' => $l->destination_id, 'nights' => $l->nights,
                'hotels' => $l->hotels->map(fn (AccommodationHotel $h) => ['id' => $h->id, 'code' => $h->code, 'name' => $h->name, 'category' => $h->category, 'provider_id' => $h->provider_id,
                    'room_types' => $h->roomTypes->map(fn (AccommodationRoomType $t) => ['room_type' => $t->room_type, 'capacity' => $t->capacity, 'price_per_night' => (string) $t->price_per_night])->all()])->all(),
            ])->all(),
            'warnings' => $warnings,
        ];
    }

    public function saveAccommodation(Product $p, array $data, User $actor): array
    {
        $enabled = array_key_exists('enabled', $data) ? filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN) : (bool) $p->accommodation_enabled;
        $optional = array_key_exists('optional', $data) ? filter_var($data['optional'], FILTER_VALIDATE_BOOLEAN) : (bool) $p->accommodation_optional;
        $currency = array_key_exists('currency', $data) ? ($data['currency'] ?: null) : $p->accommodation_currency;
        if ($currency !== null && ! in_array($currency, ['PEN', 'USD'], true)) {
            throw ApiException::invalid('currency', 'Moneda inválida.');
        }
        $note = array_key_exists('note', $data) ? (trim((string) $data['note']) ?: null) : $p->accommodation_note;
        $action = match (true) {
            $enabled && ! $p->accommodation_enabled => 'alojamiento_activar',
            ! $enabled && $p->accommodation_enabled => 'alojamiento_desactivar',
            default => 'alojamiento_config',
        };
        $before = $this->accommodationLabel($p);
        $p->forceFill(['accommodation_enabled' => $enabled, 'accommodation_optional' => $optional, 'accommodation_currency' => $currency ?? ($enabled ? 'USD' : null), 'accommodation_note' => $note])->save();
        $this->audit->log('Producto', $p->ref, $action, $before, $this->accommodationLabel($p), actor: $actor);
        $this->changed($p);

        return $this->accommodation($p);
    }

    public function saveLeg(Product $p, array $data, User $actor, ?AccommodationLeg $leg = null): AccommodationLeg
    {
        $city = trim((string) ($data['city'] ?? $leg?->city ?? ''));
        $nights = $data['nights'] ?? $leg?->nights;
        if ($city === '' || mb_strlen($city) > 80) {
            throw ApiException::invalid('city', 'Indique la ciudad del tramo.');
        }
        if (! is_numeric($nights) || (int) $nights != $nights || $nights < 1 || $nights > 30) {
            throw ApiException::unprocessable('CAT-LG-01', 'CAT-LG-01: cada tramo tiene entre 1 y 30 noches.');
        }
        $destination = array_key_exists('destination_id', $data) ? ($data['destination_id'] ?: null) : $leg?->destination_id;

        $saved = DB::transaction(function () use ($p, $leg, $city, $nights, $destination, $actor) {
            if ($leg) {
                $before = "{$leg->code} · {$leg->city} · {$leg->nights} noche(s)";
                $leg->fill(['city' => $city, 'nights' => (int) $nights, 'destination_id' => $destination])->save();
                $this->audit->log('Producto', $p->ref, 'tramo_modificar', $before, "{$leg->code} · {$leg->city} · {$leg->nights} noche(s)", actor: $actor);

                return $leg;
            }
            $n = (int) AccommodationLeg::where('product_id', $p->id)->count() + 1;
            $code = 'LEG-'.$n;
            while (AccommodationLeg::where('product_id', $p->id)->where('code', $code)->exists()) {
                $code = 'LEG-'.(++$n);
            }
            $created = AccommodationLeg::create(['product_id' => $p->id, 'code' => $code, 'sequence' => (int) AccommodationLeg::where('product_id', $p->id)->max('sequence') + 1,
                'city' => $city, 'nights' => (int) $nights, 'destination_id' => $destination]);
            $this->audit->log('Producto', $p->ref, 'tramo_crear', null, "{$created->code} · {$created->city} · {$created->nights} noche(s) · orden {$created->sequence}", actor: $actor);

            return $created;
        });
        $this->changed($p);

        return $saved;
    }

    public function deleteLeg(Product $p, AccommodationLeg $leg, User $actor): void
    {
        DB::transaction(function () use ($p, $leg, $actor) {
            $leg->delete();
            $rest = AccommodationLeg::where('product_id', $p->id)->orderBy('sequence')->get();
            foreach ($rest as $i => $l) {
                $l->update(['sequence' => $i + 1]);
            }
            $this->audit->log('Producto', $p->ref, 'tramo_eliminar', "{$leg->code} · {$leg->city} · {$leg->nights} noche(s)", null, actor: $actor);
        });
        $this->changed($p);
    }

    public function reorderLegs(Product $p, array $ids, User $actor): void
    {
        $legs = AccommodationLeg::where('product_id', $p->id)->get()->keyBy('id');
        if (count($ids) !== $legs->count() || array_diff($ids, $legs->keys()->all())) {
            throw ApiException::invalid('ids', 'Envíe todos los tramos del producto en el nuevo orden.');
        }
        DB::transaction(function () use ($p, $ids, $legs, $actor) {
            foreach (array_values($ids) as $i => $id) {
                $legs[$id]->update(['sequence' => $i + 1]);
            }
            $this->audit->log('Producto', $p->ref, 'tramo_reordenar', null, implode(' → ', array_map(fn ($id) => $legs[$id]->city, $ids)), actor: $actor);
        });
        $this->changed($p);
    }

    /** Hotel + tipos de habitación (reemplazo completo, CAT-LG-01). */
    public function saveHotel(Product $p, AccommodationLeg $leg, array $data, User $actor, ?AccommodationHotel $hotel = null): AccommodationHotel
    {
        $name = trim((string) ($data['name'] ?? $hotel?->name ?? ''));
        $category = (string) ($data['category'] ?? $hotel?->category ?? '');
        if ($name === '' || mb_strlen($name) > 150) {
            throw ApiException::invalid('name', 'Indique el nombre del hotel.');
        }
        if (! in_array($category, AccommodationHotel::CATEGORIES, true)) {
            throw ApiException::invalid('category', 'Categoría inválida.');
        }
        $dup = AccommodationHotel::where('leg_id', $leg->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($hotel, fn ($q) => $q->whereKeyNot($hotel->id))->exists();
        if ($dup) {
            throw ApiException::unprocessable('CAT-LG-01', "CAT-LG-01: el hotel «{$name}» ya está en el tramo {$leg->city}.");
        }
        $types = $data['room_types'] ?? ($hotel ? $hotel->roomTypes->map->only(['room_type', 'capacity', 'price_per_night'])->all() : []);
        $rows = [];
        foreach ((array) $types as $t) {
            if (($t['price_per_night'] ?? null) === null || $t['price_per_night'] === '') {
                continue; // fila del formulario sin precio: el tipo no se ofrece
            }
            $type = (string) ($t['room_type'] ?? '');
            if (! array_key_exists($type, AccommodationRoomType::TYPES)) {
                throw ApiException::unprocessable('CAT-LG-01', "CAT-LG-01: tipo de habitación inválido «{$type}».");
            }
            $cap = $t['capacity'] ?? AccommodationRoomType::TYPES[$type];
            if (! is_numeric($cap) || (int) $cap != $cap || $cap < 1 || $cap > 8) {
                throw ApiException::unprocessable('CAT-LG-01', 'CAT-LG-01: la capacidad de la habitación va de 1 a 8.');
            }
            if (! is_numeric($t['price_per_night'] ?? null) || $t['price_per_night'] <= 0) {
                throw ApiException::unprocessable('CAT-LG-01', 'CAT-LG-01: el precio por noche debe ser mayor a 0.');
            }
            if (isset($rows[$type])) {
                throw ApiException::unprocessable('CAT-LG-01', "CAT-LG-01: el tipo {$type} está repetido.");
            }
            $rows[$type] = ['room_type' => $type, 'capacity' => (int) $cap, 'price_per_night' => (string) $t['price_per_night']];
        }
        if ($rows === []) {
            throw ApiException::unprocessable('CAT-LG-01', 'CAT-LG-01: el hotel necesita al menos un tipo de habitación.');
        }
        $provider = array_key_exists('provider_id', $data) ? ($data['provider_id'] ?: null) : $hotel?->provider_id;

        $saved = DB::transaction(function () use ($p, $leg, $hotel, $name, $category, $provider, $rows, $actor) {
            $before = $hotel ? self::hotelLabel($hotel->load('roomTypes')) : null;
            if ($hotel) {
                $hotel->fill(['name' => $name, 'category' => $category, 'provider_id' => $provider])->save();
                AccommodationRoomType::where('hotel_id', $hotel->id)->delete();
            } else {
                $prefix = 'H-'.Str::upper(Str::substr(Str::ascii($leg->city), 0, 3)).'-';
                $n = AccommodationHotel::where('leg_id', $leg->id)->count() + 1;
                $hotel = AccommodationHotel::create(['leg_id' => $leg->id, 'code' => $prefix.$n, 'name' => $name, 'category' => $category, 'provider_id' => $provider]);
            }
            foreach ($rows as $row) {
                AccommodationRoomType::create($row + ['hotel_id' => $hotel->id]);
            }
            $hotel->load('roomTypes');
            $this->audit->log('Producto', $p->ref, $before ? 'hotel_modificar' : 'hotel_crear', $before, "{$leg->city} · ".self::hotelLabel($hotel), actor: $actor);

            return $hotel;
        });
        $this->changed($p);

        return $saved;
    }

    public function deleteHotel(Product $p, AccommodationLeg $leg, AccommodationHotel $hotel, User $actor): void
    {
        DB::transaction(function () use ($p, $leg, $hotel, $actor) {
            $label = self::hotelLabel($hotel->load('roomTypes'));
            $hotel->delete();
            $this->audit->log('Producto', $p->ref, 'hotel_eliminar', "{$leg->city} · {$label}", null, actor: $actor);
        });
        $this->changed($p);
    }

    public static function hotelLabel(AccommodationHotel $h): string
    {
        return "{$h->name} ({$h->category}): ".$h->roomTypes->map(fn ($t) => "{$t->room_type} {$t->capacity} pax {$t->price_per_night}/noche")->implode(', ');
    }

    private function accommodationLabel(Product $p): string
    {
        $legs = AccommodationLeg::where('product_id', $p->id)->get();

        return ($p->accommodation_enabled ? 'Activo' : 'Inactivo').' · '.($p->accommodation_optional ? 'opcional' : 'incluido').' · '.($p->accommodation_currency ?? '—')
            .' · '.$legs->count().' ciudad(es) · '.$legs->sum('nights').' noche(s)';
    }

    // --- pago (§3.7) ---

    public function payment(Product $p): array
    {
        $policy = PaymentPolicy::where('product_id', $p->id)->first();
        $quote = $this->engine->quote($p, QuoteSelection::fromArray(['adt' => 1]));

        return [
            'modes' => $policy?->modes ?? ['total', 'adelanto'], 'deposit_pct' => $policy?->deposit_pct !== null ? (string) $policy->deposit_pct : null,
            'balance_due_days' => $policy?->balance_due_days, 'note' => $policy?->note, 'stored' => (bool) $policy,
            'pay_later_enabled' => $this->rules->on('R-PAY-LATER', $p), 'pay_later_state' => $this->rules->state('R-PAY-LATER', $p),
            'deposit_state' => $this->rules->state('R-DEPOSIT', $p),
            'defaults' => ['pct' => $this->rules->param('R-DEPOSIT', 'pct', null, 30), 'balance_due_days' => $this->rules->param('R-DEPOSIT', 'balanceDueDays', null, 30),
                'hold_hours' => $this->rules->param('R-PAY-LATER', 'holdHours', $p, 24)],
            'effective_modes' => $quote->modes,
        ];
    }

    public function savePayment(Product $p, array $data, User $actor): array
    {
        $modes = array_values(array_intersect(['total', 'adelanto'], (array) ($data['modes'] ?? [])));
        if ($modes === []) {
            throw ApiException::unprocessable('CAT-PY-01', 'CAT-PY-01: elija al menos una modalidad con cobro (total o adelanto).');
        }
        $pct = $data['deposit_pct'] ?? null;
        if ($pct !== null && $pct !== '' && (! is_numeric($pct) || $pct < 1 || $pct > 99)) {
            throw ApiException::unprocessable('CAT-PY-01', 'CAT-PY-01: el % de adelanto va de 1 a 99.');
        }
        $days = $data['balance_due_days'] ?? null;
        if ($days !== null && $days !== '' && (! is_numeric($days) || (int) $days != $days || $days < 0 || $days > 180)) {
            throw ApiException::unprocessable('CAT-PY-01', 'CAT-PY-01: el saldo vence entre 0 y 180 días antes del viaje.');
        }
        $row = ['modes' => $modes, 'deposit_pct' => $pct === null || $pct === '' ? null : (string) $pct, 'balance_due_days' => $days === null || $days === '' ? null : (int) $days,
            'note' => trim((string) ($data['note'] ?? '')) ?: null];
        $policy = PaymentPolicy::where('product_id', $p->id)->first();
        $before = $policy ? self::paymentLabel($policy->only(['modes', 'deposit_pct', 'balance_due_days']), $this->rules->on('R-PAY-LATER', $p)) : 'hereda tenant';
        DB::transaction(function () use ($p, $row, $policy, $data) {
            $policy ? $policy->fill($row)->save() : PaymentPolicy::create($row + ['product_id' => $p->id]);
            if (array_key_exists('pay_later_enabled', $data)) {
                $this->writeOverride($p, 'R-PAY-LATER', ['enabled' => filter_var($data['pay_later_enabled'], FILTER_VALIDATE_BOOLEAN)]);
            }
        });
        if (array_key_exists('pay_later_enabled', $data)) {
            BookingRuleChanged::dispatch('R-PAY-LATER', $p->id);
        }
        $this->audit->log('Producto', $p->ref, 'politica_pago', $before, self::paymentLabel($row, $this->rules->on('R-PAY-LATER', $p)), actor: $actor);
        $this->changed($p);

        $warnings = [];
        if (filter_var($data['pay_later_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! $this->rules->tenantOn('R-PAY-LATER')) {
            $warnings[] = 'CAT-RL-02: «Reservar y pagar después» está apagada en el tenant; el producto no puede activarla.';
        }

        return ['payment' => $this->payment($p), 'warnings' => $warnings];
    }

    public static function paymentLabel(array $r, bool $payLater): string
    {
        return 'Modos: '.implode(', ', (array) $r['modes']).' · adelanto '.($r['deposit_pct'] ?? 'hereda').' % · saldo '.($r['balance_due_days'] ?? 'hereda').' día(s) antes · pagar después '.($payLater ? 'sí' : 'no');
    }

    // --- recojo (§3.8) ---

    public function pickup(Product $p): array
    {
        return ['required' => (bool) $p->pickup_required, 'ask_flight' => (bool) $p->pickup_ask_flight, 'state' => $this->rules->state('R-PICKUP', $p),
            'points' => PickupPoint::where('product_id', $p->id)->orderBy('sequence')->get()->map(fn (PickupPoint $pt) => $pt->only(['id', 'sequence', 'label', 'kind', 'requires_address']))->all()];
    }

    public function savePickup(Product $p, array $data, User $actor): array
    {
        $required = filter_var($data['required'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $flight = filter_var($data['ask_flight'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $points = [];
        foreach ((array) ($data['points'] ?? []) as $pt) {
            $label = trim((string) (is_array($pt) ? ($pt['label'] ?? '') : $pt));
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 150) {
                throw ApiException::invalid('points', 'Cada punto tiene hasta 150 caracteres.');
            }
            $key = mb_strtolower($label);
            if (isset($points[$key])) {
                throw ApiException::unprocessable('CAT-PK-01', "CAT-PK-01: el punto «{$label}» está repetido.");
            }
            $kind = is_array($pt) && in_array($pt['kind'] ?? null, PickupPoint::KINDS, true) ? $pt['kind'] : PickupPoint::kindOf($label);
            $points[$key] = ['label' => $label, 'kind' => $kind, 'requires_address' => is_array($pt) && array_key_exists('requires_address', $pt)
                ? filter_var($pt['requires_address'], FILTER_VALIDATE_BOOLEAN) : $kind === 'hotel'];
        }
        if ($required && $points === []) {
            throw ApiException::unprocessable('CAT-PK-01', 'CAT-PK-01: si el recojo es obligatorio, configure al menos un punto.');
        }
        $before = $this->pickupLabel($p);
        DB::transaction(function () use ($p, $required, $flight, $points, $actor, $before) {
            $p->forceFill(['pickup_required' => $required, 'pickup_ask_flight' => $flight])->save();
            PickupPoint::where('product_id', $p->id)->delete();
            $i = 0;
            foreach ($points as $pt) {
                PickupPoint::create($pt + ['product_id' => $p->id, 'sequence' => ++$i]);
            }
            $this->audit->log('Producto', $p->ref, 'recojo', $before, $this->pickupLabel($p), actor: $actor);
        });
        $this->changed($p);

        return $this->pickup($p);
    }

    private function pickupLabel(Product $p): string
    {
        $points = PickupPoint::where('product_id', $p->id)->orderBy('sequence')->pluck('label')->all();

        return ($p->pickup_required ? 'Obligatorio' : 'Opcional').' · vuelo '.($p->pickup_ask_flight ? 'sí' : 'no').' · '.($points ? implode(' | ', $points) : 'sin puntos');
    }

    // --- reglas del producto (§3.2) ---

    /** Reglas con override posible (con estado tenant, override y efectivo) y, al pie, las solo-tenant. */
    public function overrides(Product $p): array
    {
        $rows = [];
        foreach ($this->rules->rules() as $key => $rule) {
            $override = $this->rules->override($key, $p);
            $rows[] = [
                'key' => $key, 'group' => $rule['group'], 'name' => $rule['name'], 'description' => $rule['description'], 'product_override' => $rule['product_override'],
                'is_system' => $rule['is_system'], 'tenant_enabled' => $rule['enabled'], 'tenant_params' => (object) $rule['params'],
                'override' => $override ? ['enabled' => $override['enabled'], 'params' => (object) $override['params']] : null,
                'effective' => $this->rules->on($key, $p), 'state' => $this->rules->state($key, $p), 'params' => (object) $this->rules->params($key, $p), 'schema' => $rule['schema'],
            ];
        }

        return $rows;
    }

    public function saveOverride(Product $p, string $key, array $data, User $actor): array
    {
        $rule = $this->rules->rule($key) ?? throw ApiException::notFound();
        if (! $rule['product_override']) {
            throw ApiException::unprocessable('CAT-RL-01', "CAT-RL-01: la regla {$key} solo se configura en el tenant.");
        }
        $patch = [];
        if (array_key_exists('enabled', $data)) {
            $patch['enabled'] = $data['enabled'] === null ? null : filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('params', $data)) {
            $patch['params'] = self::validParams($rule, (array) $data['params']);
            BookingRulesService::crossCheck($key, array_replace($this->rules->params($key, $p), $patch['params']), $this->rules);
        }
        $beforeOn = $this->rules->on($key, $p);
        $beforeParams = $this->rules->params($key, $p);
        $this->writeOverride($p, $key, $patch);
        BookingRuleChanged::dispatch($key, $p->id);
        $afterOn = $this->rules->on($key, $p);
        if (array_key_exists('enabled', $patch)) {
            $this->audit->log('Producto', $p->ref, 'regla_producto', "{$key}: ".($beforeOn ? 'aplica' : 'no aplica'), "{$key}: ".($afterOn ? 'aplica' : 'no aplica'), actor: $actor);
        }
        if (array_key_exists('params', $patch)) {
            $this->audit->log('Producto', $p->ref, 'regla_parametros', "{$key}: ".json_encode((object) $beforeParams), "{$key}: ".json_encode((object) $this->rules->params($key, $p)), actor: $actor);
        }
        $this->changed($p);
        $warnings = ($patch['enabled'] ?? null) === true && ! $rule['enabled'] ? ['CAT-RL-02: la regla está apagada en el tenant; el producto no puede activarla.'] : [];

        return ['rule' => collect($this->overrides($p))->firstWhere('key', $key), 'warnings' => $warnings];
    }

    public function resetOverride(Product $p, string $key, User $actor): array
    {
        $this->rules->rule($key) ?? throw ApiException::notFound();
        $before = $this->rules->on($key, $p);
        $beforeParams = $this->rules->params($key, $p);
        RuleOverride::where('product_id', $p->id)->where('rule_key', $key)->delete();
        BookingRuleChanged::dispatch($key, $p->id);
        $this->audit->log('Producto', $p->ref, 'regla_restablecer', "{$key}: ".($before ? 'aplica' : 'no aplica').' · '.json_encode((object) $beforeParams),
            "{$key}: usa la configuración del tenant", actor: $actor);
        $this->changed($p);

        return collect($this->overrides($p))->firstWhere('key', $key);
    }

    /** CAT-RL-03: mismas claves y rangos que `params_schema`; % ≤ 100. */
    public static function validParams(array $rule, array $params): array
    {
        $schema = (array) ($rule['schema']['properties'] ?? []);
        $out = [];
        foreach ($params as $name => $value) {
            $def = (array) ($schema[$name] ?? []);
            if ($def === []) {
                throw ApiException::unprocessable('CAT-RL-03', "CAT-RL-03: parámetro desconocido «{$name}» para {$rule['key']}.");
            }
            if (($def['type'] ?? null) === 'boolean') {
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null || is_float($value) || (is_int($value) && ! in_array($value, [0, 1], true))) {
                    throw ApiException::unprocessable('CAT-RL-03', "CAT-RL-03: «{$name}» debe ser sí o no.");
                }
                $out[$name] = $bool;

                continue;
            }
            $max = $def['maximum'] ?? RuleCatalog::maxFor((string) $name);
            if (! is_numeric($value) || $value < ($def['minimum'] ?? 0) || $value > $max || (($def['type'] ?? 'number') === 'integer' && (int) $value != $value)) {
                throw ApiException::unprocessable('CAT-RL-03', "CAT-RL-03: «{$name}» debe ser un número entre ".($def['minimum'] ?? 0)." y {$max}.");
            }
            $out[$name] = ($def['type'] ?? 'number') === 'integer' ? (int) $value : $value + 0;
        }

        return $out;
    }

    /** Escribe el override guardando solo lo distinto del tenant; si no queda nada, borra la fila. */
    private function writeOverride(Product $p, string $key, array $patch): void
    {
        $rule = $this->rules->rule($key);
        $row = RuleOverride::where('product_id', $p->id)->where('rule_key', $key)->first();
        $enabled = array_key_exists('enabled', $patch) ? ($patch['enabled'] === false ? false : null) : $row?->enabled;
        $params = array_key_exists('params', $patch) ? $patch['params'] : (array) ($row?->params ?? []);
        $params = array_filter($params, fn ($v, $k) => ! array_key_exists($k, $rule['params']) || $rule['params'][$k] != $v, ARRAY_FILTER_USE_BOTH);
        if ($enabled === null && $params === []) {
            $row?->delete();
        } elseif ($row) {
            $row->fill(['enabled' => $enabled, 'params' => (object) $params])->save();
        } else {
            RuleOverride::create(['product_id' => $p->id, 'rule_key' => $key, 'enabled' => $enabled, 'params' => (object) $params]);
        }
        $this->rules->bump();
    }

    /** ProductBookingConfigChanged: la disponibilidad pública cacheada incluye reglas efectivas del producto. */
    private function changed(Product $p): void
    {
        $this->cache->bump($p);
    }
}
