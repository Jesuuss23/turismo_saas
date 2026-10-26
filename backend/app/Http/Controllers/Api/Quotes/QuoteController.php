<?php

namespace App\Http\Controllers\Api\Quotes;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Quotes\QuotePresenter;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Crm\Client;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Models\Quotes\InternalApproval;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteNegotiation;
use App\Models\Quotes\QuoteVersion;
use App\Models\Sys\AuditEvent;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmUsers;
use App\Services\Quotes\QuoteAccess;
use App\Services\Quotes\QuotePolicy;
use App\Services\Quotes\QuotePricing;
use App\Services\Quotes\QuoteService;
use App\Services\Quotes\QuoteWorkflow;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Spec 03 §6: lista, KPIs, constructor, detalle 360, versiones, duplicar, reasignar, actividad y pickers. */
class QuoteController extends Controller
{
    public function __construct(
        protected readonly QuoteAccess $access,
        protected readonly QuotePresenter $present,
        protected readonly QuoteService $quotes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $query = $this->filtered($request, $user)->with(['client:id,code,name', 'opportunity:id,code', 'currentVersion'])->orderByDesc('created_at')->orderByDesc('id');
        $perPage = min(200, max(1, (int) $request->input('per_page', 50)));
        $page = $query->cursorPaginate($perPage);
        $this->present->users->preloadUsers(collect($page->items())->pluck('owner_id'));

        return response()->json([
            'data' => collect($page->items())->map(fn (Quote $q) => $this->present->row($q, $user))->all(),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(), 'per_page' => $perPage,
                'see_margin' => $this->access->seeMargin($user), 'own_only' => $this->access->ownOnly($user),
            ],
        ]);
    }

    /** 5 KPIs con el mismo alcance que la lista (§2). */
    public function kpis(Request $request, QuotePricing $pricing): JsonResponse
    {
        $user = $this->actor($request);
        $today = $this->present->today();
        $rows = $this->scoped($request, $user)->with('currentVersion:id,grand_total,fx_rate')->get(['id', 'status', 'valid_until', 'currency', 'current_version_id']);
        $open = $rows->filter(fn (Quote $q) => in_array($q->status, Quote::EXPIRABLE, true) && ! $q->isExpired($today));
        $following = $open->filter(fn (Quote $q) => in_array($q->status, Quote::FOLLOW_UP, true));
        $won = $rows->whereIn('status', [Quote::APPROVED, Quote::CONVERTED])->count();
        $decided = $won + $rows->where('status', Quote::REJECTED)->count();
        $pending = InternalApproval::where('status', InternalApproval::PENDING)
            ->whereIn('quote_version_id', $rows->pluck('current_version_id')->filter())->count();

        $data = [
            'open' => $open->count(),
            'follow_up_pen' => (string) $following->reduce(fn (BigDecimal $sum, Quote $q) => $sum->plus($pricing->toPen((string) ($q->currentVersion?->grand_total ?? 0), $q->currency, (string) ($q->currentVersion?->fx_rate ?? 1))), BigDecimal::of('0.00')),
            'approval_rate' => $decided > 0 ? round($won / $decided * 100, 1) : 0,
            'expiring_3d' => $following->filter(fn (Quote $q) => $q->valid_until->lte($today->copy()->addDays(3)))->count(),
            'pending_internal' => $pending,
        ];
        $inbox = $user->hasPermissionTo(QuoteAccess::APPROVE_INTERNAL)
            ? InternalApproval::where('status', InternalApproval::PENDING)->with('version.quote')->orderBy('requested_at')->get()
                ->map(fn (InternalApproval $a) => ['quote' => $a->version?->quote?->code, 'version_no' => $a->version?->version_no, 'reasons' => $a->reasons,
                    'requested_by' => $this->present->users->user($a->requested_by), 'requested_at' => $a->requested_at, 'note' => $a->request_note])->all()
            : [];

        return response()->json(['data' => $data, 'meta' => ['pending_requests' => $inbox]]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);

        return response()->json(['data' => $this->present->detail($quote, $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());
        self::assertPax($data);
        $quote = $this->quotes->create($data, $this->actor($request));

        return response()->json(['data' => $this->present->detail($quote, $this->actor($request))], 201);
    }

    /** PUT /quotes/{id}/versions/{v}: edita el borrador vigente (If-Match = lock_version de la versión). */
    public function updateVersion(Request $request, string $code, int $versionNo): JsonResponse
    {
        $quote = $this->find($code, $request);
        $version = $this->version($quote, $versionNo);
        $version->assertIfMatch($request);
        $data = $request->validate($this->rules(editing: true), $this->messages());
        self::assertPax($data);
        $quote = $this->quotes->updateDraft($quote, $version, $data, $this->actor($request));

        return response()->json(['data' => $this->present->detail($quote, $this->actor($request))]);
    }

    public function versions(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);
        $margin = $this->access->seeMargin($this->actor($request), $quote);

        return response()->json(['data' => $quote->versions->map(fn (QuoteVersion $v) => $this->present->versionSummary($v, $margin))->all()]);
    }

    public function showVersion(Request $request, string $code, int $versionNo): JsonResponse
    {
        $quote = $this->find($code, $request);

        return response()->json(['data' => $this->present->version($this->version($quote, $versionNo), $this->access->seeMargin($this->actor($request), $quote))]);
    }

    /** Diff por línea (clave `productId|segment#n`) y resumen de totales. */
    public function compare(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);
        $data = $request->validate(['a' => 'required|integer', 'b' => 'required|integer']);
        $margin = $this->access->seeMargin($this->actor($request), $quote);
        [$a, $b] = [$this->version($quote, (int) $data['a']), $this->version($quote, (int) $data['b'])];
        $keyed = function (QuoteVersion $v) {
            $seen = [];

            return $v->items()->with('product:id,ref,name')->get()->mapWithKeys(function (QuoteItem $i) use (&$seen) {
                $base = "{$i->product_id}|{$i->segment}";
                $seen[$base] = ($seen[$base] ?? 0) + 1;

                return ["{$base}#{$seen[$base]}" => $i];
            });
        };
        [$la, $lb] = [$keyed($a), $keyed($b)];
        $fields = ['service_date', 'qty', 'pax', 'unit_price', 'sale_total', ...($margin ? ['unit_cost'] : [])];
        $value = fn (?QuoteItem $i, string $f) => $i === null ? null : ($i->{$f} instanceof \DateTimeInterface ? $i->{$f}->format('Y-m-d') : (string) $i->{$f});
        $lines = [];
        foreach ($la->keys()->merge($lb->keys())->unique() as $key) {
            $x = $la[$key] ?? null;
            $y = $lb[$key] ?? null;
            $changes = collect($fields)->filter(fn ($f) => $value($x, $f) !== $value($y, $f))->values()->all();
            $lines[] = [
                'key' => $key, 'product' => ($x ?? $y)->product?->ref.' · '.($x ?? $y)->product?->name, 'segment' => ($x ?? $y)->segment,
                'change' => $x === null ? 'agregado' : ($y === null ? 'eliminado' : ($changes ? 'modificado' : 'igual')),
                'a' => $x ? collect($fields)->mapWithKeys(fn ($f) => [$f => $value($x, $f)])->all() : null,
                'b' => $y ? collect($fields)->mapWithKeys(fn ($f) => [$f => $value($y, $f)])->all() : null,
                'changed_fields' => $changes,
            ];
        }
        $summary = collect(['discount_pct', 'tax_pct', 'sale_total', 'discount_total', 'net_total', 'tax_total', 'grand_total', ...($margin ? ['cost_total', 'profit_total', 'margin_pct'] : [])])
            ->map(fn ($f) => ['field' => $f, 'a' => (string) $a->{$f}, 'b' => (string) $b->{$f}, 'changed' => (string) $a->{$f} !== (string) $b->{$f}])->all();

        return response()->json(['data' => ['a' => $a->version_no, 'b' => $b->version_no, 'lines' => $lines, 'summary' => $summary,
            'differences' => collect($lines)->where('change', '!=', 'igual')->count() + collect($summary)->where('changed', true)->count()]]);
    }

    public function newVersion(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);
        $quote->assertIfMatch($request);
        $note = $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null;

        return response()->json(['data' => $this->present->detail($this->quotes->newVersion($quote, $this->actor($request), $note), $this->actor($request))], 201);
    }

    public function duplicate(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);

        return response()->json(['data' => $this->present->detail($this->quotes->duplicate($quote, $this->actor($request)), $this->actor($request))], 201);
    }

    public function reassign(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);
        $quote->assertIfMatch($request);
        $data = $request->validate(['owner_id' => 'required|uuid', 'note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present->detail($this->quotes->reassign($quote, $data['owner_id'], $data['note'] ?? null, $this->actor($request)), $this->actor($request))]);
    }

    /** Auditoría de la cotización (§9). */
    public function activity(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($code, $request);
        $rows = AuditEvent::where('entity', 'Cotización')->where('entity_id', $quote->code)->orderByDesc('occurred_at')->orderByDesc('id')->limit(300)->get();
        $this->present->users->preloadUsers($rows->pluck('actor_id'));

        return response()->json(['data' => $rows->map(fn (AuditEvent $e) => [
            'action' => $e->action, 'before' => $e->before_value, 'after' => $e->after_value,
            'actor' => $e->actor_id ? $this->present->users->user($e->actor_id) : ['name' => 'Sistema'], 'actor_role' => $e->actor_role, 'at' => $e->occurred_at,
        ])->all()]);
    }

    /** RT-5: el constructor previsualiza; el backend calcula líneas, totales, margen y motivos de aprobación. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'nullable|uuid', 'currency' => 'required|in:PEN,USD', 'fx_rate' => 'nullable|numeric|gt:0',
            'pax_adt' => 'nullable|integer|min:0', 'pax_chd' => 'nullable|integer|min:0',
            'discount_pct' => 'nullable|numeric|min:0|max:'.QuotePolicy::OVERRIDE_MAX, 'tax_pct' => 'nullable|numeric|min:0|max:18',
            ...$this->itemRules(),
        ], $this->messages());
        $user = $this->actor($request);
        $client = ! empty($data['client_id']) ? Client::find($data['client_id']) : null;
        $pricing = app(QuotePricing::class);
        $lines = $this->quotes->lines($data['items'] ?? [], [
            'currency' => $data['currency'], 'fx_rate' => (string) ($data['fx_rate'] ?? $pricing->fx()),
            'pax_adt' => (int) ($data['pax_adt'] ?? 0), 'pax_chd' => (int) ($data['pax_chd'] ?? 0),
        ], $client);
        $tax = $data['tax_pct'] ?? $pricing->defaultTax($client);
        $result = $this->quotes->evaluate($lines, $data['discount_pct'] ?? 0, $tax);
        $margin = $this->access->seeMargin($user);

        return response()->json(['data' => [
            'lines' => array_map(fn (array $l) => [
                'line_no' => $l['line_no'], 'segment' => $l['segment'], 'qty' => $l['qty'], 'pax' => $l['pax'], 'unit_price' => $l['unit_price'],
                'sale_total' => (string) BigDecimal::of($l['unit_price'])->multipliedBy($l['qty'] * $l['pax'])->toScale(2), 'season_factor' => $l['season_factor'],
                ...($margin ? ['unit_cost' => $l['unit_cost'], 'margin_pct' => QuotePricing::lineMargin($l['unit_cost'], $l['unit_price'])] : []),
            ], $lines),
            'totals' => ['sale' => $result['sale'], 'discount' => $result['discount'], 'net' => $result['net'], 'tax' => $result['tax'], 'total' => $result['total'],
                'tax_pct' => (string) $tax, 'total_pen' => $pricing->toPen($result['total'], $data['currency'], $data['fx_rate'] ?? $pricing->fx()),
                ...($margin ? ['cost' => $result['cost'], 'profit' => $result['profit'], 'margin_pct' => $result['marginPct'], 'markup_pct' => $result['markupPct']] : [])],
            'needs_internal' => $result['reasons'] !== [],
            // Ventas ve sus propios motivos (incluido el margen); quien no ve margen solo sabe que requiere aprobación.
            'reasons' => $margin ? $result['reasons'] : [],
            'over_cap' => $result['over_cap'],
        ]]);
    }

    /** Datos del constructor: clientes y oportunidades en alcance, asesores, canales, evidencias y defaults. */
    public function meta(Request $request, CrmAccess $crm, CrmUsers $users, QuotePricing $pricing): JsonResponse
    {
        $user = $this->actor($request);
        $clients = $crm->clients(Client::query(), $user)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'country', 'segment_code', 'email', 'phone']);
        $opportunities = $crm->opportunities(Opportunity::query(), $user)->whereIn('stage', Opportunity::OPEN_STAGES)->with('client:id,code,name')
            ->orderByDesc('created_at')->limit(500)->get(['id', 'code', 'title', 'client_id', 'stage', 'pax', 'currency', 'expected_close_date', 'lead_id']);

        return response()->json(['data' => [
            'clients' => $clients->map(fn (Client $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'country' => $c->country, 'segment' => $c->segment_code,
                'tax_pct' => $pricing->defaultTax($c), 'has_email' => (bool) $c->email, 'has_phone' => (bool) $c->phone])->all(),
            'opportunities' => $opportunities->map(fn (Opportunity $o) => ['id' => $o->id, 'code' => $o->code, 'title' => $o->title, 'client_id' => $o->client_id,
                'stage' => $o->stage, 'pax' => $o->pax, 'currency' => $o->currency, 'travel_date' => $o->lead?->travel_date?->toDateString() ?? $o->expected_close_date?->toDateString()])->all(),
            'owners' => $user->hasPermissionTo(QuoteAccess::REASSIGN) || ! $this->access->ownOnly($user)
                ? $users->advisors()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->roleName()])->values()->all() : [],
            'channels' => QuoteWorkflow::CHANNELS,
            'negotiation_channels' => QuoteNegotiation::CHANNELS,
            'evidence' => array_values(array_diff(QuoteWorkflow::EVIDENCE, ['Aceptación en portal (enlace con token)', 'Portal cliente'])),
            'statuses' => Quote::STATUS_LABELS,
            'segments' => QuoteItem::SEGMENTS,
            'defaults' => [
                'fx' => $pricing->fx(), 'validity_days' => $pricing->validityDays(), 'today' => $this->present->today()->toDateString(),
                'conditions' => QuoteService::DEFAULT_CONDITIONS, 'discount_internal' => QuotePolicy::DISCOUNT_INTERNAL, 'margin_min' => QuotePolicy::MARGIN_MIN,
                'max_discount' => $user->hasPermissionTo(QuoteAccess::DISCOUNT_OVERRIDE) ? QuotePolicy::OVERRIDE_MAX : QuotePolicy::MAX_DISCOUNT,
            ],
            'can' => [
                'create' => $user->hasPermissionTo(QuoteAccess::CREATE), 'send' => $user->hasPermissionTo(QuoteAccess::SEND),
                'approve_internal' => $user->hasPermissionTo(QuoteAccess::APPROVE_INTERNAL), 'discount_override' => $user->hasPermissionTo(QuoteAccess::DISCOUNT_OVERRIDE),
                'reassign' => $user->hasPermissionTo(QuoteAccess::REASSIGN), 'client_approval' => $user->hasPermissionTo(QuoteAccess::CLIENT_APPROVAL),
                'see_margin' => $this->access->seeMargin($user), 'export' => true,
            ],
            'own_only' => $this->access->ownOnly($user),
        ]]);
    }

    /** Picker del constructor: todos los productos con `blocked_reason`, tarifas vigentes a la fecha y "desde" (QTE-BR-05). */
    public function quoteable(Request $request, QuotePricing $pricing): JsonResponse
    {
        $data = $request->validate(['date' => 'nullable|date', 'currency' => 'nullable|in:PEN,USD']);
        $date = $data['date'] ?? $this->present->today()->toDateString();
        $currency = $data['currency'] ?? 'PEN';
        $fx = $pricing->fx();
        $products = Product::with(['type', 'rates', 'primaryDestination'])->where('status', '<>', Product::ARCHIVED)->orderBy('ref')->get();

        return response()->json(['data' => $products->map(function (Product $p) use ($date, $currency, $fx) {
            $valid = $p->rates->filter(fn (Rate $r) => QuotePricing::rateValidOn($r, $date) && ! $r->isNet())->values();
            $from = $valid->sortBy(fn (Rate $r) => (float) QuotePricing::convertMoney((string) $r->price, $r->currency, $currency, $fx))->first();

            return [
                'id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'type' => $p->type?->name, 'destination' => $p->primaryDestination?->name,
                'status' => $p->status, 'blocked_reason' => QuoteService::blockedReason($p),
                'rates_valid' => $valid->count(),
                'from' => $from ? QuotePricing::convertMoney((string) $from->price, $from->currency, $currency, $fx) : null,
            ];
        })->all(), 'meta' => ['date' => $date, 'currency' => $currency]]);
    }

    /** PRC-01/02/03: tarifa por defecto (o la indicada), precio sugerido y defaults de línea. */
    public function rateSuggestion(Request $request, string $product, QuotePricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date', 'currency' => 'required|in:PEN,USD', 'client_id' => 'nullable|uuid', 'rate_id' => 'nullable|uuid',
            'pax_adt' => 'nullable|integer|min:0', 'pax_chd' => 'nullable|integer|min:0', 'fx_rate' => 'nullable|numeric|gt:0',
        ]);
        $p = Product::with(['type', 'rates.season'])->where(fn ($q) => Str::isUuid($product) ? $q->whereKey($product) : $q->where('ref', $product))->first() ?? throw ApiException::notFound();
        $client = ! empty($data['client_id']) ? Client::find($data['client_id']) : null;
        $date = $data['date'];
        $fx = (string) ($data['fx_rate'] ?? $pricing->fx());
        $margin = $this->access->seeMargin($this->actor($request));
        $rates = $p->rates->reject(fn (Rate $r) => $r->isNet() && ! $margin)->sortBy('ref')->values();
        $rate = ! empty($data['rate_id'])
            ? ($rates->firstWhere('id', $data['rate_id']) ?? throw ApiException::invalid('rate_id', 'La tarifa no pertenece al producto'))
            : $pricing->defaultRate($rates, $date, $client);
        if ($rate && ! QuotePricing::rateValidOn($rate, $date)) {
            $rate = null;
        }
        $suggestion = $rate ? $pricing->suggest($rate, $date, $data['currency'], $fx) : null;
        $defaults = QuotePricing::lineDefaults($p, (int) ($data['pax_adt'] ?? 0), (int) ($data['pax_chd'] ?? 0), $rate);

        return response()->json(['data' => [
            'product' => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'type' => $p->type?->name, 'blocked_reason' => QuoteService::blockedReason($p)],
            'rate_id' => $rate?->id,
            'unit_price' => $suggestion['unit_price'] ?? null,
            ...($margin ? ['unit_cost' => $suggestion['unit_cost'] ?? null] : []),
            'season' => $suggestion['season'] ?? null,
            'season_factor' => $suggestion['season_factor'] ?? null,
            'defaults' => $defaults,
            'manual' => $rate === null,
            'notice' => $rate === null ? 'Sin tarifa vigente para la fecha: ingrese el precio manualmente' : null,
            'rates' => $rates->map(fn (Rate $r) => [
                'id' => $r->id, 'ref' => $r->ref, 'label' => "{$r->ref} · {$r->rate_type} · {$r->segment} · {$r->channel} · {$r->currency} ".number_format((float) $r->price, 2),
                'segment' => $r->segment, 'channel' => $r->channel, 'valid' => QuotePricing::rateValidOn($r, $date),
                'disabled_reason' => QuotePricing::rateValidOn($r, $date) ? null : 'no vigente (PRD-BR-005)',
            ])->all(),
        ]]);
    }

    // ── Utilidades ────────────────────────────────────────────────────

    protected function actor(Request $request): User
    {
        return $request->user();
    }

    /** Cotización por código o uuid dentro del alcance (otro tenant → 404 por RLS; ajena → 403). */
    protected function find(string $code, Request $request): Quote
    {
        $quote = Quote::where(fn ($q) => Str::isUuid($code) ? $q->whereKey($code) : $q->where('code', $code))->first() ?? throw ApiException::notFound();
        $this->access->ensureVisible($quote, $this->actor($request));

        return $quote;
    }

    protected function version(Quote $quote, int $number): QuoteVersion
    {
        return $quote->versions()->where('version_no', $number)->first() ?? throw ApiException::notFound();
    }

    private function scoped(Request $request, User $user): Builder
    {
        return $this->access->scope(Quote::query(), $user)
            ->when(! $this->access->ownOnly($user) && $request->input('filter.owner'), fn ($q, $owner) => $q->where('owner_id', $owner === 'me' ? $user->id : $owner));
    }

    private function filtered(Request $request, User $user): Builder
    {
        $today = $this->present->today()->toDateString();

        return $this->scoped($request, $user)
            ->when($request->input('filter.status'), function ($q, $status) use ($today) {
                if ($status === Quote::EXPIRED) {
                    $q->where(fn ($q) => $q->where('status', Quote::EXPIRED)->orWhere(fn ($q) => $q->whereIn('status', Quote::FOLLOW_UP)->whereDate('valid_until', '<', $today)));
                } else {
                    $q->where('status', $status)->when(in_array($status, Quote::FOLLOW_UP, true), fn ($q) => $q->whereDate('valid_until', '>=', $today));
                }
            })
            ->when($request->input('filter.internal_approval'), function ($q, $state) {
                $pending = fn ($s) => $s->selectRaw('1')->from('quote_internal_approvals as a')->whereColumn('a.quote_version_id', 'quote_quotes.current_version_id')->where('a.status', InternalApproval::PENDING);
                match ($state) {
                    'pend' => $q->whereExists($pending),
                    'ok' => $q->whereHas('currentVersion', fn ($v) => $v->whereNotNull('internal_approval_id')),
                    'req' => $q->whereHas('currentVersion', fn ($v) => $v->whereNull('internal_approval_id')
                        ->where(fn ($v) => $v->where('discount_pct', '>', QuotePolicy::DISCOUNT_INTERNAL)->orWhere('margin_pct', '<', QuotePolicy::MARGIN_MIN)))->whereNotExists($pending),
                    default => null,
                };
            })
            ->when($request->input('filter.q'), function ($q, $term) {
                $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
                $q->where(fn ($q) => $q->whereRaw('lower(quote_quotes.code) like ?', [$like])->orWhereRaw('lower(title) like ?', [$like])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw('lower(name) like ?', [$like])));
            });
    }

    /** QTE-BR-01…04, 07, 08 (máximo absoluto). */
    private function rules(bool $editing = false): array
    {
        return [
            'client_id' => [$editing ? 'sometimes' : 'required', 'uuid'],
            'opportunity_id' => 'nullable|uuid',
            'title' => 'required|string|max:200',
            'travel_from' => 'required|date',
            'travel_to' => 'required|date|after_or_equal:travel_from',
            'valid_until' => [$editing ? 'sometimes' : 'nullable', 'date'],
            'currency' => ['required', Rule::in(['PEN', 'USD'])],
            'pax_adt' => 'required|integer|min:0|max:999',
            'pax_chd' => 'required|integer|min:0|max:999',
            'pax_inf' => 'required|integer|min:0|max:999',
            'discount_pct' => 'nullable|numeric|min:0|max:'.QuotePolicy::OVERRIDE_MAX,
            'tax_pct' => 'nullable|numeric|min:0|max:18',
            'notes' => 'nullable|string|max:5000',
            'conditions' => 'nullable|string|max:5000',
            ...$this->itemRules(),
        ];
    }

    private function itemRules(): array
    {
        return [
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|uuid',
            'items.*.rate_id' => 'nullable|uuid',
            'items.*.segment' => ['nullable', Rule::in(QuoteItem::SEGMENTS)],
            'items.*.service_date' => 'required|date',
            'items.*.qty' => 'nullable|integer|min:1|max:999',
            'items.*.pax' => 'nullable|integer|min:1|max:9999',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|gt:0',
        ];
    }

    private function messages(): array
    {
        return [
            'travel_to.after_or_equal' => 'La fecha de fin del viaje no puede ser anterior al inicio.',
            'items.required' => 'Agregue al menos un ítem a la cotización.',
            'items.min' => 'Agregue al menos un ítem a la cotización.',
            'items.*.unit_price.gt' => 'El precio unitario debe ser mayor que 0.',
            'items.*.qty.min' => 'La cantidad debe ser mayor que 0.',
            'items.*.pax.min' => 'Los pax deben ser mayores que 0.',
            'discount_pct.max' => 'El descuento no puede superar el '.QuotePolicy::OVERRIDE_MAX.' %.',
        ];
    }

    /** QTE-BR-03 se valida después de las reglas por campo. */
    public static function assertPax(array $data): void
    {
        if ((int) ($data['pax_adt'] ?? 0) + (int) ($data['pax_chd'] ?? 0) + (int) ($data['pax_inf'] ?? 0) <= 0) {
            throw ApiException::invalid('pax_adt', 'Indique al menos un pasajero (ADT + CHD + INF > 0).');
        }
    }
}
