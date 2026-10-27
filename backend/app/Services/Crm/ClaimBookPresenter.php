<?php

namespace App\Services\Crm;

use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\CaseEvent;
use App\Models\Iam\User;
use App\Models\Sys\AuditEvent;
use App\Models\Sys\Holiday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Vistas del libro de reclamaciones (spec 12 §2): bandeja con KPIs, hoja, reportes, configuración y «Mis hojas» del portal. */
class ClaimBookPresenter
{
    public const TYPE_LABELS = ['reclamo' => 'Reclamo', 'queja' => 'Queja'];

    public function __construct(private readonly ClaimBookService $service, private readonly BusinessDayCalculator $days) {}

    public function row(AfterSalesCase $c): array
    {
        $consumer = $c->consumer ?? [];

        return [
            'id' => $c->id, 'code' => $c->code, 'book_number' => $c->book_number, 'type' => $c->type_code, 'type_label' => self::TYPE_LABELS[$c->type_code] ?? $c->type_code,
            'sede' => $c->sede?->name, 'sede_id' => $c->sede_id, 'channel' => $c->channel, 'channel_label' => ClaimBookService::CHANNELS[$c->channel] ?? $c->channel,
            'consumer_name' => $consumer['name'] ?? null, 'consumer_email' => $consumer['email'] ?? null, 'good' => $c->good['description'] ?? null,
            'book_status' => $c->book_status, 'status_label' => ClaimBookService::STATUS_LABELS[$c->book_status] ?? $c->book_status,
            'deadline' => $this->service->deadline($c), 'received_at' => $c->received_at?->toIso8601String(), 'due_date' => $c->due_date?->toDateString(),
            'owner' => $c->owner?->name, 'client' => $c->client ? ['id' => $c->client->id, 'code' => $c->client->code, 'name' => $c->client->name] : null,
            'reservation' => $c->reservation_id ? $c->reservation?->code : null, 'priority' => $c->priority,
        ];
    }

    public function index(array $filters): array
    {
        $all = AfterSalesCase::where('is_book', true)->with(['sede:id,name', 'owner:id,name', 'client:id,code,name', 'reservation:id,code'])->orderByDesc('received_at')->get();
        $rows = $all->map(fn ($c) => $this->row($c));
        $filtered = $rows
            ->when($filters['type'] ?? null, fn ($r, $v) => $r->where('type', $v))
            ->when($filters['sede'] ?? null, fn ($r, $v) => $r->where('sede_id', $v))
            ->when($filters['book_status'] ?? null, fn ($r, $v) => $r->where('book_status', $v))
            ->when($filters['deadline'] ?? null, fn ($r, $v) => $r->filter(fn ($x) => $x['deadline']['state'] === $v))
            ->when($filters['q'] ?? null, fn ($r, $v) => $r->filter(fn ($x) => str_contains(mb_strtolower(implode(' ', [$x['book_number'], $x['code'], $x['consumer_name'], $x['good']])), mb_strtolower($v))));
        $month = $this->service->today()->format('Y-m');

        return [
            'data' => $filtered->values()->all(),
            'kpis' => [
                'month' => $all->filter(fn ($c) => $c->received_at?->format('Y-m') === $month)->count(),
                'open' => $rows->whereIn('book_status', ['received', 'in_review'])->count(),
                'due_soon' => $rows->filter(fn ($r) => $r['deadline']['state'] === 'por_vencer')->count(),
                'overdue' => $rows->filter(fn ($r) => $r['deadline']['state'] === 'vencido')->count(),
                'avg_days' => $this->avgResponseDays($all),
            ],
        ];
    }

    public function detail(AfterSalesCase $c, User $viewer): array
    {
        $c->loadMissing(['sede', 'owner', 'client', 'reservation']);
        $events = CaseEvent::where('case_id', $c->id)->with('user:id,name')->orderBy('created_at')->get();
        $audit = AuditEvent::where('entity', 'Libro de reclamaciones')->where('entity_id', $c->book_number)->orderByDesc('occurred_at')->limit(50)->get();
        $actors = User::whereIn('id', $audit->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $steps = ['received' => 0, 'in_review' => 1, 'answered' => 2, 'closed' => 3];

        return $this->row($c) + [
            'consumer' => $c->consumer, 'good_detail' => $c->good, 'detail' => $c->detail, 'request' => $c->request, 'response' => $c->response,
            'close_note' => $c->close_note, 'closed_at' => $c->closed_at?->toIso8601String(), 'ack_sent_at' => $c->ack_sent_at?->toIso8601String(),
            'response_days' => $c->response_days, 'lock_version' => $c->lock_version, 'step' => $steps[$c->book_status] ?? 0, 'subject' => $c->subject, 'owner_id' => $c->owner_id,
            'events' => $events->map(fn ($e) => ['at' => $e->created_at?->toIso8601String(), 'event' => $e->event, 'message' => $e->message, 'user' => $e->user?->name ?? 'Consumidor / sistema'])->all(),
            'audit' => $audit->map(fn ($e) => ['at' => $e->occurred_at?->toIso8601String(), 'action' => $e->action, 'actor' => $actors[$e->actor_id] ?? 'Sistema',
                'before' => $e->before_value, 'after' => $e->after_value])->all(),
            'decisions' => ClaimBookService::DECISIONS,
            'owners' => User::permission('crm.claims.manage')->where('status', User::ACTIVE)->orderBy('name')->get(['id', 'name'])->all(),
            'can' => ['manage' => $viewer->hasPermissionTo('crm.claims.manage'),
                'review' => $c->book_status === 'received', 'respond' => in_array($c->book_status, ['received', 'in_review'], true),
                'resend' => in_array($c->book_status, ['answered', 'closed'], true), 'close' => $c->book_status === 'answered'],
        ];
    }

    /** Vista del consumidor (portal): nunca datos internos (responsable, auditoría, vínculos). */
    public function portalRow(AfterSalesCase $c): array
    {
        $r = $c->response;

        return ['code' => $c->code, 'book_number' => $c->book_number, 'type_label' => self::TYPE_LABELS[$c->type_code] ?? $c->type_code, 'sede' => $c->sede?->name,
            'status_label' => ClaimBookService::STATUS_LABELS[$c->book_status], 'book_status' => $c->book_status, 'received_at' => $c->received_at?->toIso8601String(),
            'due_date' => $c->due_date?->toDateString(), 'good' => $c->good['description'] ?? null, 'detail' => $c->detail, 'request' => $c->request,
            'response' => $r ? ['decision' => ClaimBookService::DECISIONS[$r['decision']] ?? $r['decision'], 'text' => $r['text'], 'actions' => $r['actions'], 'sent_at' => $r['sent_at']] : null];
    }

    public function reports(?int $year = null): array
    {
        $year ??= (int) $this->service->today()->format('Y');
        $all = AfterSalesCase::where('is_book', true)->with('sede:id,name')->orderBy('received_at')->get();
        $rows = $all->map(fn ($c) => $this->row($c));
        $answered = $rows->whereIn('book_status', ['answered', 'closed']);
        $inTime = $answered->filter(fn ($r) => $r['deadline']['state'] === 'respondido_en_plazo')->count();
        $months = collect(range(5, 0))->map(function ($i) use ($all) {
            $m = $this->service->today()->startOfMonth()->subMonths($i);
            $key = $m->format('Y-m');

            return ['label' => $m->locale('es')->translatedFormat('M Y'), 'values' => [
                $all->filter(fn ($c) => $c->received_at?->format('Y-m') === $key)->count(),
                $all->filter(fn ($c) => isset($c->response['answered_at']) && Carbon::parse($c->response['answered_at'])->format('Y-m') === $key)->count(),
            ]];
        })->all();

        return [
            'by_type' => $rows->groupBy('type_label')->map(fn ($r, $k) => ['label' => $k, 'value' => $r->count()])->values()->all(),
            'by_sede' => $rows->groupBy('sede')->map(fn ($r, $k) => ['label' => $k ?: '—', 'value' => $r->count()])->values()->all(),
            'compliance' => ['answered' => $answered->count(), 'in_time' => $inTime, 'late' => $answered->count() - $inTime,
                'overdue_open' => $rows->filter(fn ($r) => $r['deadline']['state'] === 'vencido')->count(),
                'pct' => $answered->count() ? round($inTime / $answered->count() * 100, 1) : null],
            'by_month' => ['series' => ['Recibidas', 'Respondidas'], 'rows' => $months],
            'overdue' => $rows->filter(fn ($r) => $r['deadline']['state'] === 'vencido')->values()->all(),
            'year' => $year, 'annual' => $this->annual($year),
        ];
    }

    /** Relación anual para INDECOPI: una fila por hoja del año. */
    public function annual(int $year): array
    {
        return AfterSalesCase::where('is_book', true)->with('sede:id,name')->whereYear('received_at', $year)->orderBy('book_number')->get()
            ->map(function (AfterSalesCase $c) {
                $r = $c->response;

                return [$c->book_number, $c->received_at?->format('d/m/Y'), $c->sede?->name, self::TYPE_LABELS[$c->type_code] ?? $c->type_code,
                    $c->consumer['name'] ?? '', ($c->consumer['doc_type'] ?? '').' '.($c->consumer['doc_number'] ?? ''), $c->good['description'] ?? '',
                    $c->due_date?->format('d/m/Y'), $r ? Carbon::parse($r['sent_at'])->format('d/m/Y') : '', $r ? (ClaimBookService::DECISIONS[$r['decision']] ?? '') : '',
                    ClaimBookService::STATUS_LABELS[$c->book_status], $r ? (($r['late'] ?? false) ? 'Fuera de plazo' : 'En plazo') : ($this->service->deadline($c)['label'])];
            })->all();
    }

    public const ANNUAL_HEADER = ['N.° hoja', 'Fecha', 'Sede', 'Tipo', 'Consumidor', 'Documento', 'Bien contratado', 'Vence', 'Respondida', 'Decisión', 'Estado', 'Plazo'];

    public function settings(User $viewer): array
    {
        $s = $this->service->settings();
        $year = (int) $this->service->today()->format('Y');
        $counters = DB::table('crm_claim_book_counters')->get()->keyBy(fn ($r) => "{$r->sede_id}:{$r->year}");

        return ['response_days' => $s->response_days, 'alert_days' => $s->alert_days, 'retention_years' => $s->retention_years, 'default_owner_id' => $s->default_owner_id,
            'lock_version' => $s->lock_version, 'can_edit' => $viewer->hasPermissionTo('admin.settings.manage'),
            'owners' => User::permission('crm.claims.manage')->where('status', User::ACTIVE)->orderBy('name')->get(['id', 'name'])->all(),
            'holidays' => Holiday::whereYear('date', '>=', $year)->orderBy('date')->get()->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name,
                'local' => $h->tenant_id !== null])->all(),
            'sequences' => $this->service->sedes()->map(fn ($sede) => ['sede' => $sede->name, 'prefix' => "LR-{$this->service->sedePrefix($sede)}-{$year}-",
                'last' => (int) ($counters["{$sede->id}:{$year}"]->last_number ?? 0)])->all()];
    }

    private function avgResponseDays(Collection $cases): ?float
    {
        $answered = $cases->filter(fn ($c) => isset($c->response['answered_at']));
        if ($answered->isEmpty()) {
            return null;
        }

        return round($answered->avg(fn ($c) => $this->days->between(Carbon::parse($c->received_at->toDateString()), Carbon::parse(Carbon::parse($c->response['answered_at'])->toDateString()))), 1);
    }
}
