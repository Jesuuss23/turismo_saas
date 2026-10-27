<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Channel\B2bCreditMovement;
use App\Models\Channel\B2bPriceList;
use App\Models\Crm\Client;
use App\Models\Finance\Commission;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\ClientService;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use App\Services\Notify;
use App\Services\SequenceService;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Administración B2B (spec 08 §4.1, PROC-053): alta/edición con bloqueo automático, bloquear/desbloquear y KPIs del canal. */
class B2bAgencyService
{
    public const FIELDS = ['name', 'ruc', 'contact_name', 'booking_email', 'price_list_id', 'commission_pct', 'credit_currency', 'credit_limit', 'valid_from', 'valid_to', 'status'];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly ClientService $clients,
        private readonly B2bCredit $credit,
        private readonly FxRates $fx,
    ) {}

    /** KPIs de la cabecera del canal (§2). */
    public function summary(): array
    {
        $agencies = B2bAgency::all();
        $restricted = $agencies->filter(fn (B2bAgency $a) => $a->effectiveStatus() !== 'activa');
        $sales = Reservation::where('channel', 'B2B')->whereNotIn('status', [Reservation::CANCELLED, Reservation::DRAFT])->get(['total_amount', 'currency', 'fx_rate']);
        $pending = Commission::where('kind', 'agencia_b2b')->where('status', '<>', 'pagada')->get(['amount', 'currency', 'fx_rate']);

        return [
            'active' => $agencies->filter(fn (B2bAgency $a) => $a->effectiveStatus() === 'activa')->count(),
            'total' => $agencies->count(),
            'credit_granted' => Fin::money($agencies->sum(fn (B2bAgency $a) => $this->pen($a, (float) $a->credit_limit))),
            'credit_used' => Fin::money($agencies->sum(fn (B2bAgency $a) => $this->pen($a, (float) $a->credit_used))),
            'sales' => Fin::money($sales->sum(fn ($r) => $this->fx->toPen($r->total_amount, $r->currency, $r->fx_rate))),
            'sales_count' => $sales->count(),
            'commissions_pending' => Fin::money($pending->sum(fn ($c) => $this->fx->toPen($c->amount, $c->currency, $c->fx_rate))),
            'restricted' => $restricted->map(fn (B2bAgency $a) => ['code' => $a->code, 'name' => $a->name, 'status' => $a->effectiveStatus(),
                'status_label' => B2bAgency::STATUS_LABELS[$a->effectiveStatus()], 'reason' => $this->restriction($a)])->values()->all(),
        ];
    }

    public function restriction(B2bAgency $a): ?string
    {
        return match ($a->effectiveStatus()) {
            'vencido' => 'Convenio vencido el '.B2bTerms::date($a->valid_to).': no puede reservar.',
            'inactiva' => 'Agencia inactiva: no puede reservar ni ingresar al portal.',
            'bloqueada' => $a->exceeded()
                ? "Crédito excedido ({$a->credit_currency} {$a->credit_used} de {$a->credit_limit}): solo prepago."
                : 'Bloqueada manualmente'.($a->block_note ? ": {$a->block_note}" : '').'. Solo prepago.',
            default => null,
        };
    }

    /** @return Collection<int, B2bAgency> con filtros por estado efectivo, lista y texto. */
    public function list(array $filter): Collection
    {
        $q = trim((string) ($filter['q'] ?? ''));

        return B2bAgency::with('priceList')
            ->when($filter['price_list'] ?? null, fn ($w, $list) => $w->whereHas('priceList', fn ($p) => $p->where('id', $list)->orWhere('name', $list)))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%'])->orWhere('ruc', 'like', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%")))
            ->orderBy('code')->get()
            ->when($filter['status'] ?? null, fn (Collection $c, $status) => $c->filter(fn (B2bAgency $a) => $a->effectiveStatus() === $status)->values());
    }

    public function create(array $data, User $actor): B2bAgency
    {
        $data = $this->validate($data);
        if (empty($data['booking_email'])) {
            throw ApiException::invalid('booking_email', 'Indique el correo de reservas: recibe vouchers y avisos del convenio.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $client = Client::where('doc_type', 'RUC')->where('doc_number', $data['ruc'])->first()
                ?? $this->clients->create(['kind' => 'empresa', 'name' => $data['name'], 'doc_type' => 'RUC', 'doc_number' => $data['ruc'],
                    'email' => $data['booking_email'], 'segment_code' => 'b2b', 'tags' => ['B2B']],
                    $actor, true, ['name' => $data['contact_name'], 'role' => 'Contacto de convenio', 'email' => $data['booking_email'] ?? null]);
            $agency = B2bAgency::create($data + ['code' => $this->sequences->next('b2b_agency'), 'client_id' => $client->id, 'credit_used' => 0,
                'block_reason' => ($data['status'] ?? 'activa') === 'bloqueada' ? 'manual' : null]);
            $client->forceFill(['b2b_agency_id' => $agency->id])->save();
            $this->audit->log('Agencia B2B', $agency->code, 'crear', null, $this->describe($agency), actor: $actor);

            return $agency->fresh('priceList');
        });
    }

    /** B2B-BR-08: guardar con crédito excedido bloquea; el cambio de límite queda con antes/después. @return array{agency: B2bAgency, warnings: list<string>} */
    public function update(B2bAgency $agency, array $data, User $actor): array
    {
        $data = $this->validate($data + $agency->only(self::FIELDS), $agency);

        return DB::transaction(function () use ($agency, $data, $actor) {
            $agency = $this->credit->lock($agency);
            $before = $this->describe($agency);
            $limitBefore = (string) $agency->credit_limit;
            $agency->fill($data);
            $warnings = [];
            if ($agency->exceeded()) {
                $agency->status = 'bloqueada';
                $agency->block_reason = 'credito_excedido';
                $warnings[] = "Crédito utilizado ({$agency->credit_currency} {$agency->credit_used}) supera el límite: la agencia queda bloqueada (B2B-BR-08).";
            } elseif ($agency->isDirty('status')) {
                $agency->block_reason = $agency->status === 'bloqueada' ? 'manual' : null;
            }
            $agency->save();
            $this->audit->log('Agencia B2B', $agency->code, 'editar', $before, $this->describe($agency), actor: $actor);
            if ($limitBefore !== (string) $agency->credit_limit) {
                $this->audit->log('Agencia B2B', $agency->code, 'cambiar_limite_credito', "{$agency->credit_currency} {$limitBefore}", "{$agency->credit_currency} {$agency->credit_limit}", actor: $actor);
            }

            return ['agency' => $agency->fresh('priceList'), 'warnings' => $warnings];
        });
    }

    public function block(B2bAgency $agency, string $reason, User $actor): B2bAgency
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo del bloqueo.');
        }

        return DB::transaction(function () use ($agency, $reason, $actor) {
            $agency = $this->credit->lock($agency);
            if ($agency->status === 'bloqueada') {
                throw ApiException::rule('B2B-BR-07', "La agencia {$agency->name} ya está bloqueada.");
            }
            $before = B2bAgency::STATUS_LABELS[$agency->effectiveStatus()];
            $agency->forceFill(['status' => 'bloqueada', 'block_reason' => 'manual', 'block_note' => mb_substr($reason, 0, 300)])->save();
            $this->audit->log('Agencia B2B', $agency->code, 'bloquear', $before, "Bloqueada · {$reason}", actor: $actor);
            $this->notifyAgency($agency, "Su convenio B2B fue bloqueado: {$reason}. Puede seguir reservando con prepago.");

            return $agency->fresh('priceList');
        });
    }

    /** B2B-BR-07: prohibido mientras el crédito utilizado supere el límite. */
    public function unblock(B2bAgency $agency, ?string $reason, User $actor): B2bAgency
    {
        return DB::transaction(function () use ($agency, $reason, $actor) {
            $agency = $this->credit->lock($agency);
            if ($agency->exceeded()) {
                throw ApiException::rule('B2B-BR-07', "No se puede desbloquear: el crédito utilizado ({$agency->credit_currency} {$agency->credit_used}) supera el límite ({$agency->credit_limit}). Amplíe el límite o registre pagos (Regla 14).");
            }
            if ($agency->status !== 'bloqueada') {
                throw ApiException::rule('B2B-BR-07', "La agencia {$agency->name} no está bloqueada.");
            }
            $agency->forceFill(['status' => 'activa', 'block_reason' => null, 'block_note' => null])->save();
            $this->audit->log('Agencia B2B', $agency->code, 'desbloquear', 'Bloqueada', 'Activa'.(trim((string) $reason) !== '' ? ' · '.trim((string) $reason) : ''), actor: $actor);
            $this->notifyAgency($agency, 'Su convenio B2B fue desbloqueado: ya puede reservar con crédito.');

            return $agency->fresh('priceList');
        });
    }

    /** @return Collection<int, B2bCreditMovement> */
    public function movements(B2bAgency $agency): Collection
    {
        return B2bCreditMovement::where('agency_id', $agency->id)->with('reservation:id,code', 'payment:id,code')->orderByDesc('created_at')->limit(200)->get();
    }

    public function priceLists(): Collection
    {
        return B2bPriceList::orderBy('name')->get();
    }

    /** B2B-BR-09. */
    private function validate(array $data, ?B2bAgency $current = null): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
        foreach (['name' => 'la razón social', 'contact_name' => 'el contacto', 'valid_from' => 'el inicio de la vigencia', 'valid_to' => 'el fin de la vigencia'] as $field => $label) {
            if (($data[$field] ?? '') === '' || ($data[$field] ?? null) === null) {
                throw ApiException::invalid($field, "Indique {$label}.");
            }
        }
        $data['valid_from'] = (string) ($data['valid_from'] instanceof DateTimeInterface ? $data['valid_from']->format('Y-m-d') : $data['valid_from']);
        $data['valid_to'] = (string) ($data['valid_to'] instanceof DateTimeInterface ? $data['valid_to']->format('Y-m-d') : $data['valid_to']);
        if (! preg_match('/^\d{11}$/', (string) ($data['ruc'] ?? ''))) {
            throw ApiException::invalid('ruc', 'El RUC debe tener 11 dígitos.');
        }
        if (B2bAgency::where('ruc', $data['ruc'])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
            throw ApiException::invalid('ruc', 'Ya existe una agencia con ese RUC.');
        }
        $pct = $data['commission_pct'] ?? null;
        if (! is_numeric($pct) || (float) $pct < 0 || (float) $pct > 30) {
            throw ApiException::invalid('commission_pct', 'La comisión debe estar entre 0 y 30 %.');
        }
        if (! is_numeric($data['credit_limit'] ?? 0) || (float) ($data['credit_limit'] ?? 0) < 0) {
            throw ApiException::invalid('credit_limit', 'El límite de crédito no puede ser negativo.');
        }
        if ($data['valid_to'] < $data['valid_from']) {
            throw ApiException::invalid('valid_to', 'La vigencia "hasta" debe ser igual o posterior a "desde".');
        }
        if (! B2bPriceList::whereKey($data['price_list_id'] ?? null)->exists()) {
            throw ApiException::invalid('price_list_id', 'Elija la lista de precios.');
        }
        if (! empty($data['booking_email']) && ! filter_var($data['booking_email'], FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid('booking_email', 'Correo de reservas inválido.');
        }
        $data['status'] = in_array($data['status'] ?? 'activa', ['activa', 'bloqueada', 'inactiva'], true) ? ($data['status'] ?? 'activa') : 'activa';
        $data['credit_currency'] = in_array($data['credit_currency'] ?? 'PEN', ['PEN', 'USD'], true) ? ($data['credit_currency'] ?? 'PEN') : 'PEN';
        $data['credit_limit'] = Fin::money($data['credit_limit'] ?? 0);
        $data['commission_pct'] = Fin::money($pct);

        return $data;
    }

    private function describe(B2bAgency $a): string
    {
        $list = B2bPriceList::find($a->price_list_id)?->name ?? '—';

        return "{$a->name} · lista {$list} · comisión {$a->commission_pct} % · límite {$a->credit_currency} {$a->credit_limit} · ".(B2bAgency::STATUS_LABELS[$a->status] ?? $a->status);
    }

    private function pen(B2bAgency $a, float $amount): float
    {
        return $a->credit_currency === 'PEN' ? $amount : $this->fx->toPen($amount, $a->credit_currency);
    }

    private function notifyAgency(B2bAgency $agency, string $subject): void
    {
        $email = $agency->booking_email ?: $agency->client?->email;
        if ($email) {
            $this->notify->push('b2b.estado', 'email', $email, $subject, "Agencia {$agency->name} ({$agency->code})",
                ['client_id' => $agency->client_id, 'related_type' => 'b2b_agency', 'related_id' => $agency->id]);
        }
    }
}
