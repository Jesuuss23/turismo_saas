<?php

namespace App\Services\Crm\Targets;

use App\Exceptions\ApiException;
use App\Models\Crm\SalesTarget;
use App\Models\Crm\SalesTargetAdjustment;
use App\Models\Crm\SalesTeam;
use App\Models\Finance\Commission;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Metas comerciales (spec 14 §4.5): borrador → vigente → cerrada, o anulada. MET-BR-01..06 y MET-BR-10 (bono por tramos → comisión
 * «Bono por meta» calculada, que Finanzas aprueba y paga en PROC-047).
 */
class SalesTargetService
{
    public function __construct(
        private readonly TargetProgress $progress,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
    ) {}

    public function create(array $data, User $actor): SalesTarget
    {
        $scopeType = (string) ($data['scope_type'] ?? 'asesor');
        if (! isset(SalesTarget::SCOPE_LABELS[$scopeType])) {
            throw ApiException::invalid('scope_type', 'El alcance debe ser asesor, sede o equipo.');
        }
        $scopeId = $this->scope($scopeType, (string) ($data['scope_id'] ?? ''));
        $periodType = (string) ($data['period_type'] ?? 'mes');
        $periodKey = trim((string) ($data['period_key'] ?? ''));
        $valid = $periodType === 'mes' ? preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodKey) : ($periodType === 'trimestre' && preg_match('/^\d{4}-Q[1-4]$/', $periodKey));
        if (! $valid) {
            throw ApiException::invalid('period_key', 'Indique el período (AAAA-MM o AAAA-Qn).');
        }
        [$from, $to] = TargetProgress::period($periodType, $periodKey);
        if ($to->toDateString() < $this->clock->today()->toDateString()) {
            throw ApiException::unprocessable('MET-BR-03', 'No se crean metas para períodos terminados.');
        }
        $goals = $this->goals($data, $scopeType, null);
        $activate = filter_var($data['activate'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $t = DB::transaction(function () use ($scopeType, $scopeId, $periodType, $periodKey, $from, $to, $goals, $activate, $actor) {
                $dup = SalesTarget::where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('period_key', $periodKey)->where('status', '<>', 'anulada')->first();
                if ($dup) {
                    throw ApiException::rule('MET-BR-01', "Ya existe la meta {$dup->code} para ese alcance y período.", ['existing' => $dup->code]);
                }
                $t = SalesTarget::create($goals + ['code' => $this->sequences->next('sales_target'), 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                    'period_type' => $periodType, 'period_key' => $periodKey, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
                    'bonus_currency' => $goals['bonus_amount'] !== null ? 'PEN' : null, 'status' => $activate ? 'vigente' : 'borrador']);
                $this->history($t, 'crear', null, $this->snapshot($t), null, $actor);
                $this->audit->log('Meta comercial', $t->code, 'crear', null, "{$this->scopeName($t)} · {$t->period_key} · S/ ".number_format((float) $t->goal_sales_pen, 2)
                    .($activate ? ' · Vigente' : ' · Borrador'), actor: $actor);

                return $t;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::rule('MET-BR-01', 'Ya existe una meta para ese alcance y período.');
        }
        if ($activate) {
            $this->notifyAdvisor($t, "Nueva meta {$t->period_key}", 'Se le asignó la meta '.$t->code.' de S/ '.number_format((float) $t->goal_sales_pen, 2).'.');
        }

        return $t;
    }

    public function activate(SalesTarget $target, User $actor): SalesTarget
    {
        $t = DB::transaction(function () use ($target, $actor) {
            $t = $this->lock($target);
            if ($t->status !== 'borrador') {
                throw ApiException::rule('MET-TRANSITION', 'Solo se activa una meta en borrador.');
            }
            $t->forceFill(['status' => 'vigente'])->save();
            $this->history($t, 'activar', null, null, null, $actor);
            $this->audit->log('Meta comercial', $t->code, 'activar', 'Borrador', 'Vigente', actor: $actor);

            return $t;
        });
        $this->notifyAdvisor($t, "Meta {$t->period_key} vigente", "La meta {$t->code} de S/ ".number_format((float) $t->goal_sales_pen, 2).' está vigente.');

        return $t;
    }

    /** MET-BR-04 (motivo + antes/después) y MET-BR-05 (reducir tras el 50 % del período solo Tenant Admin). */
    public function adjust(SalesTarget $target, array $data, User $actor): SalesTarget
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ApiException::unprocessable('MET-BR-04', 'Todo ajuste exige un motivo.');
        }
        $t = DB::transaction(function () use ($target, $data, $reason, $actor) {
            $t = $this->lock($target);
            if (! in_array($t->status, ['borrador', 'vigente'], true)) {
                throw ApiException::rule('MET-TRANSITION', 'Solo se ajustan metas en borrador o vigentes.');
            }
            $before = $this->snapshot($t);
            $goals = $this->goals(($data['goals'] ?? []) + $data, $t->scope_type, $t);
            $after = ['goal_sales_pen' => (float) $goals['goal_sales_pen'], 'goal_bookings' => $goals['goal_bookings'],
                'goal_margin_pen' => $goals['goal_margin_pen'] === null ? null : (float) $goals['goal_margin_pen'],
                'goal_conversion_pct' => $goals['goal_conversion_pct'] === null ? null : (float) $goals['goal_conversion_pct'],
                'bonus_amount' => $goals['bonus_amount'] === null ? null : (float) $goals['bonus_amount']];
            if ($after == $before) {
                throw ApiException::invalid('goals', 'No hay cambios respecto de la meta actual.');
            }
            $decreases = collect($after)->filter(fn ($v, $k) => $before[$k] !== null && ($v === null || $v < $before[$k]))->keys();
            $elapsed = $this->progress->progress($t)['days']['elapsed_pct'];
            $limit = $this->progress->policy()->lock_decrease_after_pct;
            if ($t->status === 'vigente' && $decreases->isNotEmpty() && $elapsed > $limit && $actor->roleName() !== 'tenant_admin') {
                throw ApiException::forbidden('MET-BR-05', "Con {$elapsed} % del período transcurrido, reducir la meta requiere al Tenant Admin (anti-manipulación del bono).");
            }
            $t->forceFill($goals)->save();
            $this->history($t, 'ajustar', $before, $after, $reason, $actor);
            $this->audit->log('Meta comercial', $t->code, 'ajustar', $this->describe($before), $this->describe($after).' · '.$reason, actor: $actor);

            return $t;
        });
        if ($t->status === 'vigente') {
            $this->notifyAdvisor($t, "Meta {$t->code} ajustada", "Su meta {$t->period_key} se ajustó: {$this->describe($this->snapshot($t))}. Motivo: {$reason}");
        }

        return $t;
    }

    public function void(SalesTarget $target, string $reason, User $actor): SalesTarget
    {
        if (trim($reason) === '') {
            throw ApiException::unprocessable('MET-BR-04', 'Indique el motivo de la anulación.');
        }

        return DB::transaction(function () use ($target, $reason, $actor) {
            $t = $this->lock($target);
            if ($t->status !== 'borrador') {
                throw ApiException::rule('MET-TRANSITION', 'Solo se anula una meta en borrador.');
            }
            $t->forceFill(['status' => 'anulada'])->save();
            $this->history($t, 'anular', null, null, trim($reason), $actor);
            $this->audit->log('Meta comercial', $t->code, 'anular', 'Borrador', 'Anulada · '.trim($reason), actor: $actor);

            return $t;
        });
    }

    /** MET-BR-06: solo tras el fin del período; congela el resultado y, si hay bono, crea UNA comisión «Bono por meta» (MET-BR-10). */
    public function close(SalesTarget $target, User $actor): SalesTarget
    {
        $commission = null;
        $t = DB::transaction(function () use ($target, $actor, &$commission) {
            $t = $this->lock($target);
            if ($t->status !== 'vigente') {
                throw ApiException::rule('MET-BR-06', $t->status === 'cerrada' ? "La meta {$t->code} ya está cerrada." : 'Solo se cierra una meta vigente.');
            }
            if ($t->period_to->toDateString() >= $this->clock->today()->toDateString()) {
                throw ApiException::unprocessable('MET-BR-06', 'El período termina el '.$t->period_to->format('d/m/Y').': se cierra después de esa fecha.');
            }
            $reservations = $this->progress->reservations($t);
            $actual = $this->progress->actual($t, $reservations);
            $pct = round(100 * $actual['sales'] / (float) $t->goal_sales_pen, 1);
            $t->forceFill(['status' => 'cerrada', 'result' => $actual + ['pct' => $pct, 'reservation_ids' => $reservations->pluck('id')->all(),
                'closed_at' => now()->toIso8601String(), 'closed_by' => $actor->id]])->save();
            $this->history($t, 'cerrar', null, ['sales' => $actual['sales'], 'pct' => $pct], null, $actor);
            $this->audit->log('Meta comercial', $t->code, 'cerrar', 'Vigente', 'Cerrada · '.number_format($pct, 1, ',', '.').' % de cumplimiento', actor: $actor);
            $bonus = $this->progress->bonus($t, $pct);
            if ($bonus['amount'] > 0 && ! Commission::where('sales_target_id', $t->id)->exists()) {
                $commission = Commission::create(['code' => $this->sequences->next('commission'), 'reservation_id' => null, 'sales_target_id' => $t->id, 'kind' => 'bono_meta',
                    'beneficiary_user_id' => $t->scope_id, 'rule_description' => "Bono por meta {$t->code} ({$t->period_key}) · ".number_format($pct, 1, ',', '.')
                        ." % → tramo ≥ {$bonus['tier']['min']} % paga {$bonus['tier']['pay']} %", 'base_amount' => Fin::money($bonus['base']),
                    'pct' => number_format((float) $bonus['tier']['pay'], 2, '.', ''), 'amount' => Fin::money($bonus['amount']), 'currency' => 'PEN', 'status' => 'calculada']);
                $t->forceFill(['bonus_commission_id' => $commission->id])->save();
                Fin::history('commission', $commission->id, 'calculada', $commission->rule_description, $actor);
                $this->history($t, 'bono', null, ['commission' => $commission->code, 'amount' => $bonus['amount']], null, $actor);
                $this->audit->log('Comisión', $commission->code, 'calcular', null, "Bono por meta {$t->code} · {$commission->user?->name} · PEN {$commission->amount}", actor: $actor);
            }

            return $t;
        });
        if ($commission) {
            foreach (User::permission('finance.commissions')->where('status', User::ACTIVE)->get() as $finance) {
                $this->notify->push('comision.bono_meta', 'email', $finance, "Bono por meta {$t->code}: PEN {$commission->amount} por aprobar",
                    "Comisión {$commission->code} calculada para {$commission->user?->name}.", ['related_type' => 'commission', 'related_id' => $commission->id]);
            }
        }
        $pct = $t->result['pct'];
        $this->notifyAdvisor($t, "Meta {$t->code} cerrada", 'Cumplimiento '.number_format($pct, 1, ',', '.').' %'.($commission ? " · bono PEN {$commission->amount} calculado" : ''));

        return $t;
    }

    /** Ranking del período (asesores con meta vigente o cerrada). Para Ventas sin montos ni bonos ajenos (MET-BR-07). */
    public function ranking(string $periodKey, User $viewer): array
    {
        $manage = $viewer->hasPermissionTo('crm.targets.manage');
        $targets = SalesTarget::where('scope_type', 'asesor')->where('period_key', $periodKey)->whereIn('status', ['vigente', 'cerrada'])->get();
        $users = User::whereIn('id', $targets->pluck('scope_id'))->pluck('name', 'id');
        $rows = $targets->map(function (SalesTarget $t) use ($users) {
            $p = $this->progress->progress($t);

            return ['code' => $t->code, 'user_id' => $t->scope_id, 'name' => $users[$t->scope_id] ?? '—', 'pct' => $p['pct']['sales'] ?? 0, 'sales' => $p['actual']['sales'],
                'goal' => (float) $t->goal_sales_pen, 'projection_pct' => $p['projection']['pct'], 'bonus' => $p['bonus']['amount'], 'health' => $p['health'], 'health_label' => $p['health_label']];
        })->sortByDesc('pct')->values();

        return $rows->map(function (array $r, int $i) use ($manage, $viewer) {
            $r['position'] = $i + 1;
            $r['me'] = $r['user_id'] === $viewer->id;
            if (! $manage && ! $r['me']) {
                unset($r['sales'], $r['goal'], $r['bonus'], $r['code']);
            }

            return $r;
        })->all();
    }

    /** JOB-19: metas vigentes con período terminado → aviso al Manager. */
    public function pendingClose(): string
    {
        $pending = SalesTarget::where('status', 'vigente')->whereDate('period_to', '<', $this->clock->today()->toDateString())->orderBy('code')->get();
        if ($pending->isNotEmpty()) {
            foreach (User::permission('crm.targets.manage')->where('status', User::ACTIVE)->get() as $m) {
                if ($m->roleName() === 'manager') {
                    $this->notify->push('meta.por_cerrar', 'portal', $m, "{$pending->count()} meta(s) por cerrar", $pending->pluck('code')->implode(', '), ['related_type' => 'sales_target']);
                }
            }
        }
        $this->audit->log('Job', 'targets:pending-close', 'ejecutar', null, "{$pending->count()} meta(s) por cerrar", system: true);

        return "{$pending->count()} meta(s) por cerrar";
    }

    public function scopeName(SalesTarget $t): string
    {
        return match ($t->scope_type) {
            'asesor' => (string) User::find($t->scope_id)?->name,
            'sede' => (string) Sede::find($t->scope_id)?->name,
            default => (string) SalesTeam::find($t->scope_id)?->name,
        };
    }

    public function describe(array $goals): string
    {
        $parts = ['Ventas S/ '.number_format((float) $goals['goal_sales_pen'], 2)];
        if ($goals['goal_bookings'] !== null) {
            $parts[] = "{$goals['goal_bookings']} reservas";
        }
        if ($goals['goal_margin_pen'] !== null) {
            $parts[] = 'margen S/ '.number_format((float) $goals['goal_margin_pen'], 2);
        }
        if ($goals['goal_conversion_pct'] !== null) {
            $parts[] = "conversión {$goals['goal_conversion_pct']} %";
        }
        if ($goals['bonus_amount'] !== null) {
            $parts[] = 'bono S/ '.number_format((float) $goals['bonus_amount'], 2);
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, float|int|null> */
    public function snapshot(SalesTarget $t): array
    {
        return ['goal_sales_pen' => (float) $t->goal_sales_pen, 'goal_bookings' => $t->goal_bookings, 'goal_margin_pen' => $t->goal_margin_pen === null ? null : (float) $t->goal_margin_pen,
            'goal_conversion_pct' => $t->goal_conversion_pct === null ? null : (float) $t->goal_conversion_pct, 'bonus_amount' => $t->bonus_amount === null ? null : (float) $t->bonus_amount];
    }

    /** Usuarios internos que pueden tener meta individual (con `crm.targets.view`). @return Collection<int, User> */
    public function advisors(): Collection
    {
        return User::permission('crm.targets.view')->where('status', User::ACTIVE)->orderBy('name')->get()->filter(fn (User $u) => $u->roleName() === 'sales')->values();
    }

    /** MET-BR-02: ventas > 0 obligatoria; reservas, margen y conversión (0–100) opcionales; bono solo asesor. */
    private function goals(array $data, string $scopeType, ?SalesTarget $current): array
    {
        $num = fn (string $key) => array_key_exists($key, $data) ? ($data[$key] === null || $data[$key] === '' ? null : $data[$key]) : ($current?->{$key});
        $sales = $num('goal_sales_pen');
        if (! is_numeric($sales) || (float) $sales <= 0) {
            throw ApiException::unprocessable('MET-BR-02', 'La meta de ventas es obligatoria y mayor que 0.');
        }
        $bookings = $num('goal_bookings');
        if ($bookings !== null && (! is_numeric($bookings) || (int) $bookings < 0)) {
            throw ApiException::unprocessable('MET-BR-02', 'La meta de reservas no puede ser negativa.');
        }
        $margin = $num('goal_margin_pen');
        if ($margin !== null && (! is_numeric($margin) || (float) $margin < 0)) {
            throw ApiException::unprocessable('MET-BR-02', 'La meta de margen no puede ser negativa.');
        }
        $conversion = $num('goal_conversion_pct');
        if ($conversion !== null && (! is_numeric($conversion) || (float) $conversion < 0 || (float) $conversion > 100)) {
            throw ApiException::unprocessable('MET-BR-02', 'La conversión debe estar entre 0 y 100 %.');
        }
        $bonus = $num('bonus_amount');
        if ($bonus !== null && $scopeType !== 'asesor') {
            throw ApiException::invalid('bonus_amount', 'El bono solo aplica a metas por asesor.');
        }
        if ($bonus !== null && (! is_numeric($bonus) || (float) $bonus < 0)) {
            throw ApiException::invalid('bonus_amount', 'El bono no puede ser negativo.');
        }

        return ['goal_sales_pen' => round((float) $sales, 2), 'goal_bookings' => $bookings === null ? null : (int) $bookings,
            'goal_margin_pen' => $margin === null ? null : round((float) $margin, 2), 'goal_conversion_pct' => $conversion === null ? null : round((float) $conversion, 2),
            'bonus_amount' => $bonus === null ? null : round((float) $bonus, 2)];
    }

    private function scope(string $type, string $ref): string
    {
        $byRef = fn ($q, string $col) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where($col, $ref);
        $id = match ($type) {
            'asesor' => $byRef(User::query(), 'code')->where('status', User::ACTIVE)->first()?->id,
            'sede' => $byRef(Sede::query(), 'code')->first()?->id,
            default => $byRef(SalesTeam::query(), 'code')->first()?->id,
        };

        return $id ?? throw ApiException::invalid('scope_id', 'Elija el '.mb_strtolower(SalesTarget::SCOPE_LABELS[$type]).' de la meta.');
    }

    private function history(SalesTarget $t, string $action, ?array $before, ?array $after, ?string $reason, User $actor): void
    {
        SalesTargetAdjustment::create(['target_id' => $t->id, 'action' => $action, 'before' => $before, 'after' => $after, 'reason' => $reason,
            'actor_id' => $actor->id, 'occurred_at' => now()]);
    }

    private function notifyAdvisor(SalesTarget $t, string $subject, string $body): void
    {
        if ($t->scope_type !== 'asesor' || ! ($user = User::find($t->scope_id))) {
            return;
        }
        foreach (['portal', 'email'] as $channel) {
            $this->notify->push('meta.asignada', $channel, $user, $subject, $body, ['related_type' => 'sales_target', 'related_id' => $t->id]);
        }
    }

    private function lock(SalesTarget $target): SalesTarget
    {
        $t = SalesTarget::whereKey($target->id)->lockForUpdate()->firstOrFail();
        if (request()->hasHeader('If-Match')) {
            $t->assertIfMatch(request());
        }

        return $t;
    }
}
