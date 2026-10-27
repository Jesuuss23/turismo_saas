<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Models\Inventory\Allotment;
use App\Models\Inventory\AllotmentConsumption;
use App\Models\Inventory\AllotmentDay;
use App\Models\Inventory\AllotmentEvent;
use App\Models\Inventory\AllotmentRequest;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Contratos de cupo (spec 11 §4.1, §4.3, §5 CUP-BR-001..007): alta/edición con días, liberación, retención y ampliaciones. */
class AllotmentService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
    ) {}

    /** Fecha del tenant a medianoche UTC: comparable con las columnas `date` (casteadas en UTC). */
    public function today(): Carbon
    {
        return Carbon::parse($this->clock->today()->toDateString());
    }

    /** POST /catalog/allotments: valida, crea el contrato y una fila por fecha de la vigencia. */
    public function create(array $data, User $actor): Allotment
    {
        [$provider, $service, $kind] = $this->validated($data);

        $allotment = DB::transaction(function () use ($data, $actor, $provider, $service, $kind) {
            try {
                $a = Allotment::create($this->attributes($data, $kind) + [
                    'code' => $this->sequences->next('allotment'), 'provider_id' => $provider->id, 'service_id' => $service->id,
                    'status' => 'activo', 'owner_id' => $actor->id,
                ]);
            } catch (QueryException $e) {
                throw $this->overlapOr($e);
            }
            $this->syncDays($a);
            $this->event($a, "Contrato registrado: {$a->base_qty} ".Allotment::UNITS[$a->kind]." del {$a->valid_from->format('d/m/Y')} al {$a->valid_to->format('d/m/Y')}", $actor);
            $this->audit->log('Cupo', $a->code, 'crear', null, "{$a->name} · {$provider->name} · {$a->base_qty} {$this->unit($a)} · release {$a->release_days} d", actor: $actor);

            return $a;
        });
        app(AllotmentConsumer::class)->backfill($allotment);
        $this->resolveStatus($allotment);

        return $allotment->fresh();
    }

    /** PATCH: edita condiciones y regenera días (no se pueden quitar fechas con consumo o cargo). */
    public function update(Allotment $a, array $data, User $actor): Allotment
    {
        if ($a->status === 'liberado') {
            throw ApiException::rule('CUP-STATE', 'El contrato está liberado: no admite cambios.');
        }
        $data += ['provider_id' => $a->provider_id, 'service_id' => $a->service_id];
        foreach (['name', 'contract_ref', 'valid_from', 'valid_to', 'base_qty', 'release_days', 'net_rate', 'currency', 'penalty_pct', 'notes'] as $key) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $a->{$key} instanceof Carbon ? $a->{$key}->toDateString() : $a->{$key};
            }
        }
        [, $service, $kind] = $this->validated($data, $a);
        $before = $this->summary($a);
        DB::transaction(function () use ($a, $data, $kind, $service) {
            $oldBase = $a->base_qty;
            $a->fill($this->attributes($data, $kind) + ['service_id' => $service->id, 'provider_id' => $service->provider_id]);
            if ($a->status === 'vencido' && $a->valid_to->toDateString() >= $this->today()->toDateString()) {
                $a->status = 'activo'; // §4.1 vencido → activo al extender la vigencia
            }
            try {
                $a->save();
            } catch (QueryException $e) {
                throw $this->overlapOr($e);
            }
            $this->syncDays($a, $a->base_qty - $oldBase);
        });
        $this->event($a, 'Condiciones editadas: '.$this->summary($a), $actor);
        $this->audit->log('Cupo', $a->code, 'editar_contrato', $before, $this->summary($a), actor: $actor);
        app(AllotmentConsumer::class)->backfill($a);
        $this->resolveStatus($a);

        return $a->fresh();
    }

    /** POST /{id}/release: libera todo el contrato (§4.1). Libera lo disponible futuro y borra retenciones. */
    public function releaseContract(Allotment $a, string $reason, User $actor): Allotment
    {
        if (! $a->isLive()) {
            throw ApiException::rule('CUP-STATE', 'Solo se libera un contrato activo o en release.');
        }
        $reason = trim($reason) ?: throw ApiException::invalid('reason', 'Indique el motivo de la liberación.');
        $units = 0;
        DB::transaction(function () use ($a, $reason, $actor, &$units) {
            foreach (AllotmentDay::where('allotment_id', $a->id)->whereDate('service_date', '>=', $this->today()->toDateString())->lockForUpdate()->get() as $day) {
                $free = max(0, $day->available());
                $units += $free;
                $day->forceFill(['released_qty' => $day->released_qty + $free, 'retained_reason' => null, 'retained_by' => null, 'retained_at' => null])->save();
            }
            $a->forceFill(['status' => 'liberado', 'released_at' => now(), 'release_reason' => mb_substr($reason, 0, 2000)])->save();
            $this->event($a, "Contrato liberado ({$units} {$this->unit($a)} futuras): {$reason}", $actor);
            $this->audit->log('Cupo', $a->code, 'liberar_contrato', Allotment::STATUS_LABELS['activo'], "Liberado · {$units} {$this->unit($a)} · {$reason}", actor: $actor);
        });
        $this->notifyProvider($a, 'cupo.liberacion', "Liberación total del cupo {$a->code}", "{$a->name}: liberamos {$units} {$this->unit($a)} futuras. Motivo: {$reason}");

        return $a->fresh();
    }

    /** POST /{id}/days/{date}/release (CUP-BR-005): solo lo disponible; lock por fecha + lock_version. */
    public function releaseDay(AllotmentDay $day, int $qty, User $actor, ?int $expectedVersion = null): AllotmentDay
    {
        $a = $day->allotment;
        $lock = Cache::lock("allot:{$a->id}:{$day->service_date->toDateString()}", 10);
        if (! $lock->get()) {
            throw ApiException::conflict('LOCKED', 'Otra liberación de esta fecha está en curso. Intente de nuevo.');
        }
        try {
            $day = DB::transaction(function () use ($day, $qty, $actor, $a, $expectedVersion) {
                $day = AllotmentDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && $expectedVersion !== (int) $day->lock_version) {
                    throw ApiException::conflict('STALE_VERSION', 'La fecha cambió desde que la abrió: recargue el cupo.');
                }
                $this->assertOpen($a, $day);
                if ($qty < 1 || $qty > $day->available()) {
                    throw ApiException::unprocessable('CUP-BR-005', "Solo se puede liberar lo disponible ({$day->available()} {$this->unit($a)}); lo consumido no se libera.");
                }
                $before = "{$day->released_qty} liberado(s), disponible {$day->available()}";
                $day->released_qty += $qty;
                $day->save();
                $date = $day->service_date->format('d/m/Y');
                $this->event($a, "Liberadas {$qty} {$this->unit($a)} del {$date}", $actor);
                $this->audit->log('Cupo', $a->code, 'liberar', "{$date} · {$before}", "{$date} · {$day->released_qty} liberado(s), disponible {$day->available()}", actor: $actor);

                return $day;
            });
        } finally {
            $lock->release();
        }
        $this->notifyProvider($a, 'cupo.liberacion', "Liberación de cupo {$a->code}", "{$a->name}: liberamos {$qty} {$this->unit($a)} del {$day->service_date->format('d/m/Y')}.");
        $this->resolveStatus($a);

        return $day->fresh();
    }

    /** PUT /retention (CUP-BR-007): retener tras el release expone a penalidad; se informa el monto. */
    public function retain(AllotmentDay $day, string $reason, User $actor): AllotmentDay
    {
        $a = $day->allotment;
        $this->assertOpen($a, $day);
        $reason = trim($reason) ?: throw ApiException::invalid('reason', 'Indique el motivo de la retención.');
        $exposure = $this->exposure($a, $day);
        $day->forceFill(['retained_reason' => mb_substr($reason, 0, 200), 'retained_by' => $actor->id, 'retained_at' => now()])->save();
        $text = "Retenidas {$day->available()} {$this->unit($a)} del {$day->service_date->format('d/m/Y')}: {$reason}";
        $this->event($a, $text.($exposure > 0 ? " · exposición {$a->currency} ".number_format($exposure, 2, '.', '') : ''), $actor);
        $this->audit->log('Cupo', $a->code, 'retener', null, $text." · exposición {$a->currency} ".number_format($exposure, 2, '.', ''), actor: $actor);
        $this->resolveStatus($a);

        return $day->fresh();
    }

    public function unretain(AllotmentDay $day, User $actor): AllotmentDay
    {
        $a = $day->allotment;
        if ($day->retained_reason === null) {
            throw ApiException::rule('CUP-STATE', 'La fecha no está retenida.');
        }
        $before = $day->retained_reason;
        $day->forceFill(['retained_reason' => null, 'retained_by' => null, 'retained_at' => null])->save();
        $this->event($a, "Retención quitada del {$day->service_date->format('d/m/Y')}", $actor);
        $this->audit->log('Cupo', $a->code, 'quitar_retencion', $before, $day->service_date->format('d/m/Y'), actor: $actor);
        $this->resolveStatus($a);

        return $day->fresh();
    }

    /** POST /{id}/requests (CUP-BR-004): ampliación futura y dentro de la vigencia. */
    public function requestExpansion(Allotment $a, array $data, User $actor): AllotmentRequest
    {
        if (! $a->isLive()) {
            throw ApiException::rule('CUP-STATE', 'Solo se amplía un contrato activo o en release.');
        }
        $from = $this->date($data['date_from'] ?? null, 'date_from');
        $to = $this->date($data['date_to'] ?? null, 'date_to');
        if ($to->lt($from)) {
            throw ApiException::invalid('date_to', 'La fecha final no puede ser anterior a la inicial.');
        }
        if ($from->lte($this->today()) || $from->lt($a->valid_from) || $to->gt($a->valid_to)) {
            throw ApiException::unprocessable('CUP-BR-004', "La ampliación debe ser futura y dentro de la vigencia ({$a->valid_from->format('d/m/Y')} – {$a->valid_to->format('d/m/Y')}).");
        }
        $qty = (int) ($data['extra_qty'] ?? 0);
        if ($qty < 1 || $qty > 999) {
            throw ApiException::invalid('extra_qty', 'Indique cuántas unidades adicionales solicita.');
        }
        $note = trim((string) ($data['note'] ?? '')) ?: throw ApiException::invalid('note', 'Indique el motivo para el proveedor.');
        $request = AllotmentRequest::create(['code' => $this->sequences->next('allotment_request'), 'allotment_id' => $a->id,
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'extra_qty' => $qty, 'note' => mb_substr($note, 0, 2000), 'status' => 'solicitada']);
        $range = "{$from->format('d/m')}–{$to->format('d/m')}";
        $this->event($a, "Ampliación {$request->code} solicitada: +{$qty} {$this->unit($a)} del {$range}", $actor);
        $this->audit->log('Cupo', $a->code, 'solicitar_ampliacion', null, "{$request->code} · +{$qty} · {$range} · {$note}", actor: $actor);
        $this->notifyProvider($a, 'cupo.ampliacion', "Solicitud de ampliación {$request->code}", "{$a->name}: solicitamos +{$qty} {$this->unit($a)} del {$range}. {$note}");

        return $request;
    }

    /** POST /requests/{rid}/respond (§4.3): aceptar suma `extra_qty` a cada fecha del rango; ambos con respuesta. */
    public function respond(AllotmentRequest $request, bool $accepted, string $response, User $actor): AllotmentRequest
    {
        $response = trim($response) ?: throw ApiException::invalid('response', 'Registre la respuesta del proveedor.');
        $a = $request->allotment;

        return DB::transaction(function () use ($request, $accepted, $response, $actor, $a) {
            $request = AllotmentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'solicitada') {
                throw ApiException::rule('CUP-STATE', "La ampliación ya fue {$request->status}.");
            }
            $request->forceFill(['status' => $accepted ? 'aceptada' : 'rechazada', 'response' => mb_substr($response, 0, 2000),
                'responded_at' => now(), 'responded_by' => $actor->id])->save();
            if ($accepted) {
                AllotmentDay::where('allotment_id', $a->id)->whereBetween('service_date', [$request->date_from->toDateString(), $request->date_to->toDateString()])
                    ->update(['blocked_qty' => DB::raw('blocked_qty + '.(int) $request->extra_qty), 'updated_at' => now()]);
            }
            $verb = $accepted ? 'aceptada' : 'rechazada';
            $this->event($a, "Ampliación {$request->code} {$verb}: {$response}", $actor);
            $this->audit->log('Cupo', $a->code, "ampliacion_{$verb}", 'Solicitada', "{$request->code} · ".($accepted ? "+{$request->extra_qty} " : '')."· {$response}", actor: $actor);
            $this->resolveStatus($a);

            return $request->fresh();
        });
    }

    /**
     * `AllotmentStatusResolver` (§4.1): en_release si hay ≥ 1 fecha futura con release vencido, disponible > 0 y sin retención.
     * Liberado y vencido no cambian aquí (los decide la acción o el job).
     */
    public function resolveStatus(Allotment $a): void
    {
        $a = Allotment::find($a->id);
        if (! $a || ! $a->isLive()) {
            return;
        }
        $today = $this->today()->toDateString();
        $due = AllotmentDay::where('allotment_id', $a->id)->whereDate('service_date', '>=', $today)->whereDate('release_deadline', '<=', $today)
            ->whereNull('retained_reason')->whereRaw('blocked_qty - released_qty - used_qty > 0')->exists();
        $status = $due ? 'en_release' : 'activo';
        if ($status !== $a->status) {
            Allotment::whereKey($a->id)->update(['status' => $status, 'updated_at' => now()]);
            $this->event($a, $due ? 'Pasa a en release: hay fechas con release vencido sin liberar' : 'Vuelve a activo: release al día', null);
        }
    }

    /** CUP-BR-007: lo que costaría no usar lo disponible (penalidad sobre la tarifa neta). */
    public function exposure(Allotment $a, AllotmentDay $day): float
    {
        return round(max(0, $day->available()) * (float) $a->net_rate * (float) $a->penalty_pct / 100, 2);
    }

    public function unit(Allotment $a): string
    {
        return Allotment::UNITS[$a->kind] ?? 'unid.';
    }

    public function event(Allotment $a, string $message, ?User $actor): void
    {
        AllotmentEvent::create(['allotment_id' => $a->id, 'occurred_at' => now(), 'user_id' => $actor?->id, 'message' => mb_substr($message, 0, 300)]);
    }

    public function notifyProvider(Allotment $a, string $event, string $subject, string $body): void
    {
        $email = $a->provider?->email;
        if ($email) {
            $this->notify->push($event, 'email', $email, $subject, $body, ['related_type' => 'catalog_allotments', 'related_id' => $a->id]);
        }
    }

    /** Día de una fecha del contrato (404 si está fuera de la vigencia). */
    public function day(Allotment $a, string $date): AllotmentDay
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? AllotmentDay::where('allotment_id', $a->id)->whereDate('service_date', $date)->first() : null;

        return $day?->setRelation('allotment', $a) ?? throw ApiException::notFound();
    }

    private function assertOpen(Allotment $a, AllotmentDay $day): void
    {
        if (! $a->isLive()) {
            throw ApiException::rule('CUP-STATE', 'El contrato no está vigente ('.mb_strtolower(Allotment::STATUS_LABELS[$a->status]).').');
        }
        if ($day->service_date->lt($this->today())) {
            throw ApiException::rule('CUP-STATE', 'La fecha ya pasó: la cierra el job de release.');
        }
    }

    /** CUP-BR-001/002 (+ formato). @return array{0: Provider, 1: SupplierService, 2: string} */
    private function validated(array $data, ?Allotment $current = null): array
    {
        $provider = Provider::find($data['provider_id'] ?? null) ?? throw ApiException::invalid('provider_id', 'Seleccione el proveedor.');
        $service = SupplierService::find($data['service_id'] ?? null) ?? throw ApiException::invalid('service_id', 'Seleccione el servicio.');
        if ($service->provider_id !== $provider->id) {
            throw ApiException::unprocessable('CUP-BR-002', "El servicio {$service->name} no pertenece a {$provider->name}.");
        }
        $kind = Allotment::SERVICE_TYPES[$service->type] ?? throw ApiException::unprocessable('CUP-BR-002', 'Solo se contratan cupos de Alojamiento, Tren o Entrada.');
        if ($current === null && ($provider->status !== 'activo' || $service->status !== 'activo')) {
            throw ApiException::unprocessable('CUP-BR-002', 'El proveedor y el servicio deben estar activos.');
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw ApiException::invalid('name', 'Indique el nombre del contrato.');
        }
        $from = $this->date($data['valid_from'] ?? null, 'valid_from');
        $to = $this->date($data['valid_to'] ?? null, 'valid_to');
        if ($to->lt($from)) {
            throw ApiException::unprocessable('CUP-BR-001', 'La vigencia termina antes de empezar.');
        }
        if ($to->diffInDays($from, true) > 730) {
            throw ApiException::invalid('valid_to', 'La vigencia no puede superar 2 años.');
        }
        $checks = [
            'base_qty' => [fn ($v) => is_numeric($v) && (int) $v >= 1 && (int) $v <= 9999, 'El bloqueo base debe ser mayor a 0.'],
            'release_days' => [fn ($v) => is_numeric($v) && (int) $v >= 0 && (int) $v <= 120, 'Los días de release van de 0 a 120.'],
            'net_rate' => [fn ($v) => is_numeric($v) && (float) $v >= 0, 'La tarifa neta no puede ser negativa.'],
            'penalty_pct' => [fn ($v) => is_numeric($v ?? 0) && (float) ($v ?? 0) >= 0 && (float) ($v ?? 0) <= 100, 'La penalidad va de 0 a 100 %.'],
            'currency' => [fn ($v) => in_array($v, ['PEN', 'USD'], true), 'Moneda PEN o USD.'],
        ];
        foreach ($checks as $field => [$ok, $message]) {
            if (! $ok($data[$field] ?? null)) {
                throw ApiException::invalid($field, $message);
            }
        }
        // CUP-BR-003 antes del EXCLUDE (mensaje claro); la restricción cubre la carrera.
        $overlap = Allotment::where('service_id', $service->id)->whereIn('status', Allotment::LIVE)->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->whereDate('valid_from', '<=', $to->toDateString())->whereDate('valid_to', '>=', $from->toDateString())->first();
        if ($overlap) {
            throw ApiException::rule('CUP-BR-003', "Ya existe el contrato vigente {$overlap->code} para este servicio en esas fechas.");
        }

        return [$provider, $service, $kind];
    }

    private function attributes(array $data, string $kind): array
    {
        return [
            'kind' => $kind, 'name' => mb_substr(trim($data['name']), 0, 150), 'contract_ref' => trim((string) ($data['contract_ref'] ?? '')) ?: null,
            'valid_from' => $data['valid_from'], 'valid_to' => $data['valid_to'], 'base_qty' => (int) $data['base_qty'], 'release_days' => (int) $data['release_days'],
            'net_rate' => number_format((float) $data['net_rate'], 2, '.', ''), 'currency' => $data['currency'], 'penalty_pct' => (float) ($data['penalty_pct'] ?? 0),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    /**
     * Una fila por fecha de vigencia. Al editar: agrega fechas nuevas, quita las que salen de la vigencia (si no tienen consumo
     * ni cargo), ajusta el bloqueo por la diferencia del base (conserva ampliaciones) y recalcula la fecha de release.
     */
    private function syncDays(Allotment $a, int $baseDelta = 0): void
    {
        $from = $a->valid_from->toDateString();
        $to = $a->valid_to->toDateString();
        $outside = AllotmentDay::where('allotment_id', $a->id)->where(fn ($q) => $q->whereDate('service_date', '<', $from)->orWhereDate('service_date', '>', $to));
        $blocked = (clone $outside)->where(fn ($q) => $q->where('used_qty', '>', 0)->orWhere('pending_qty', '>', 0)->orWhereHas('charge'))->exists()
            || AllotmentConsumption::whereIn('allotment_day_id', (clone $outside)->select('id'))->where('state', '<>', 'revertido')->exists();
        if ($blocked) {
            throw ApiException::unprocessable('CUP-BR-001', 'No se puede acortar la vigencia: hay fechas fuera del nuevo rango con consumo o cargos.');
        }
        AllotmentConsumption::whereIn('allotment_day_id', (clone $outside)->select('id'))->delete();
        $outside->delete();
        if ($baseDelta !== 0) {
            AllotmentDay::where('allotment_id', $a->id)->update(['blocked_qty' => DB::raw('GREATEST(released_qty, blocked_qty + '.$baseDelta.')')]);
        }
        AllotmentDay::where('allotment_id', $a->id)->update(['release_deadline' => DB::raw("service_date - {$a->release_days}"), 'updated_at' => now()]);
        $existing = AllotmentDay::where('allotment_id', $a->id)->pluck('service_date')->map(fn ($d) => $d->toDateString())->flip();
        $rows = [];
        for ($d = $a->valid_from->copy(); $d->lte($a->valid_to); $d->addDay()) {
            if (! isset($existing[$d->toDateString()])) {
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $a->tenant_id, 'allotment_id' => $a->id, 'service_date' => $d->toDateString(),
                    'blocked_qty' => $a->base_qty, 'release_deadline' => $d->copy()->subDays($a->release_days)->toDateString(),
                    'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            AllotmentDay::insert($chunk);
        }
    }

    private function overlapOr(QueryException $e): ApiException|QueryException
    {
        return str_contains($e->getMessage(), 'no_overlap')
            ? ApiException::rule('CUP-BR-003', 'Ya existe un contrato vigente para este servicio en esas fechas.')
            : $e;
    }

    private function date(mixed $value, string $field): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ApiException::invalid($field, 'Indique una fecha válida.');
        }

        return Carbon::parse($value)->startOfDay();
    }

    private function summary(Allotment $a): string
    {
        return "{$a->valid_from->format('d/m/Y')}–{$a->valid_to->format('d/m/Y')} · {$a->base_qty} {$this->unit($a)} · release {$a->release_days} d · {$a->currency} {$a->net_rate} · penalidad {$a->penalty_pct} %";
    }
}
