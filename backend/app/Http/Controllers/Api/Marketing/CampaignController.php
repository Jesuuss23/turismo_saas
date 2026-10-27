<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Marketing\Automation;
use App\Models\Marketing\Campaign;
use App\Models\Marketing\CampaignRecipient;
use App\Services\Marketing\AutomationService;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\ConsentService;
use App\Services\Marketing\MarketingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Spec 14 §6: campañas (máquina de estados §4.1) y automatizaciones. */
class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns, private readonly MarketingPresenter $present) {}

    public function index(Request $request): JsonResponse
    {
        $filters = (array) $request->input('filter', []);
        $rows = Campaign::with(['segment', 'template', 'owner'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->orderByDesc('created_at')->get();
        $metrics = $this->present->metrics($rows->pluck('id')->all());

        return response()->json(['data' => $rows->map(fn ($c) => $this->present->campaignRow($c, $metrics))->values(), 'meta' => ['kpis' => $this->present->kpis()]]);
    }

    public function show(string $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present->campaign($this->find($campaign))]);
    }

    public function store(Request $request): JsonResponse
    {
        $c = $this->campaigns->create($request->all(), $request->user());

        return response()->json(['data' => ['id' => $c->id, 'code' => $c->code], 'message' => "Campaña {$c->code} creada en borrador"], 201);
    }

    public function update(Request $request, string $campaign): JsonResponse
    {
        $c = $this->find($campaign)->assertIfMatch($request);
        $c = $this->campaigns->update($c, $request->all(), $request->user());

        return response()->json(['data' => ['id' => $c->id, 'code' => $c->code, 'lock_version' => $c->lock_version], 'message' => "Campaña {$c->code} guardada"]);
    }

    public function audience(Request $request, string $campaign): JsonResponse
    {
        $c = $this->find($campaign);
        $at = $request->filled('at') ? Carbon::parse((string) $request->input('at')) : null;
        $a = $this->campaigns->audience($c, $at);

        return response()->json(['data' => ['matched' => $a['matched'], 'by_type' => $a['by_type'], 'eligible' => $a['eligible']->count(), 'counts' => $a['counts'], 'at' => $a['at'],
            'recipients' => $a['eligible']->map(fn ($r) => ['type' => $r['type'], 'code' => $r['code'], 'name' => $r['name'], 'address' => $r['address']])->values(),
            'suppressed' => $a['suppressed']->map(fn ($r) => ['type' => $r['type'], 'code' => $r['code'], 'name' => $r['name'], 'reason' => $r['reason']])->values()]]);
    }

    public function testSend(Request $request, string $campaign): JsonResponse
    {
        $t = $this->campaigns->testSend($this->find($campaign), $request->all(), $request->user());

        return response()->json(['data' => ['to' => $t->to_address, 'sent_at' => $t->sent_at->toIso8601String()], 'message' => "Prueba enviada a {$t->to_address}"]);
    }

    public function schedule(Request $request, string $campaign): JsonResponse
    {
        $c = $this->find($campaign)->assertIfMatch($request);
        $c = $this->campaigns->schedule($c, $request->input('scheduled_at'), $request->user());

        return response()->json(['data' => ['code' => $c->code, 'status' => $c->status, 'lock_version' => $c->lock_version], 'message' => "Campaña {$c->code} programada"]);
    }

    public function unschedule(Request $request, string $campaign): JsonResponse
    {
        $c = $this->find($campaign)->assertIfMatch($request);
        $c = $this->campaigns->unschedule($c, $request->user());

        return response()->json(['data' => ['code' => $c->code, 'status' => $c->status, 'lock_version' => $c->lock_version], 'message' => "Campaña {$c->code} de vuelta a borrador"]);
    }

    public function send(Request $request, string $campaign): JsonResponse
    {
        $c = $this->find($campaign);
        if ($request->hasHeader('If-Match')) {
            $c->assertIfMatch($request);
        }
        $c = $this->campaigns->send($c, $request->user());

        return response()->json(['data' => ['code' => $c->code, 'status' => $c->status, 'snapshot' => $c->audience_snapshot, 'lock_version' => $c->lock_version],
            'message' => "Campaña {$c->code} en envío a {$c->audience_snapshot['eligible']} destinatario(s)"]);
    }

    /** «Procesar envío (job)»: ejecuta en línea el lote pendiente (lo mismo que hace SendCampaignBatch). */
    public function process(string $campaign): JsonResponse
    {
        $c = $this->find($campaign);
        $r = $this->campaigns->processBatch($c);
        $c->refresh();

        return response()->json(['data' => $r + ['status' => $c->status], 'message' => "Lote procesado: {$r['sent']} enviado(s), {$r['suppressed']} suprimido(s)".
            ($c->status === 'enviada' ? ' · campaña enviada' : '')]);
    }

    public function cancel(Request $request, string $campaign): JsonResponse
    {
        $c = $this->find($campaign)->assertIfMatch($request);
        $c = $this->campaigns->cancel($c, (string) $request->input('reason'), $request->user());

        return response()->json(['data' => ['code' => $c->code, 'status' => $c->status], 'message' => "Campaña {$c->code} cancelada"]);
    }

    public function duplicate(Request $request, string $campaign): JsonResponse
    {
        $c = $this->campaigns->duplicate($this->find($campaign), $request->user());

        return response()->json(['data' => ['id' => $c->id, 'code' => $c->code], 'message' => "Campaña {$c->code} creada como copia"], 201);
    }

    public function recipients(Request $request, string $campaign): JsonResponse
    {
        $c = $this->find($campaign);
        $page = CampaignRecipient::where('campaign_id', $c->id)->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')->paginate(min(200, (int) $request->input('per_page', 50)));

        return response()->json(['data' => collect($page->items())->map(fn (CampaignRecipient $r) => ['id' => $r->id, 'type' => $r->subject_type, 'name' => $r->name,
            'address' => $r->address, 'status' => $r->status, 'status_label' => CampaignRecipient::STATUS_LABELS[$r->status], 'sent_at' => $r->sent_at?->toIso8601String(),
            'opened_at' => $r->opened_at?->toIso8601String(), 'unsubscribed_at' => $r->unsubscribed_at?->toIso8601String()]),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function unsubscribe(Request $request, ConsentService $consents, string $campaign, string $recipient): JsonResponse
    {
        $c = $this->find($campaign);
        $r = CampaignRecipient::where('campaign_id', $c->id)->whereKey($recipient)->first() ?? throw ApiException::notFound();
        $consent = $consents->unsubscribeRecipient($c, $r, $request->user());

        return response()->json(['data' => ['consent' => $consent->code, 'status' => $consent->status], 'message' => "Baja de {$r->name} registrada"]);
    }

    // ── Automatizaciones ─────────────────────────────────────────────

    public function automations(): JsonResponse
    {
        return response()->json(['data' => Automation::with('template')->orderBy('code')->get()->map(fn ($a) => $this->present->automation($a))]);
    }

    public function activateAutomation(Request $request, AutomationService $automations, string $automation): JsonResponse
    {
        $a = $automations->activate($this->automation($automation)->assertIfMatch($request), $request->user());

        return response()->json(['data' => ['code' => $a->code, 'status' => $a->status, 'lock_version' => $a->lock_version], 'message' => "{$a->name}: activa"]);
    }

    public function pauseAutomation(Request $request, AutomationService $automations, string $automation): JsonResponse
    {
        $a = $automations->pause($this->automation($automation)->assertIfMatch($request), $request->user());

        return response()->json(['data' => ['code' => $a->code, 'status' => $a->status, 'lock_version' => $a->lock_version], 'message' => "{$a->name}: pausada"]);
    }

    public function configureAutomation(Request $request, AutomationService $automations, string $automation): JsonResponse
    {
        $a = $automations->configure($this->automation($automation), $request->all(), $request->user());

        return response()->json(['data' => $this->present->automation($a, false), 'message' => "{$a->name}: configuración guardada"]);
    }

    public function runAutomation(Request $request, AutomationService $automations, string $automation): JsonResponse
    {
        $run = $automations->run($this->automation($automation), $request->user());

        return response()->json(['data' => ['queued' => $run->queued, 'sent' => $run->sent, 'suppressed' => $run->suppressed],
            'message' => "Ejecución: {$run->sent} enviado(s), {$run->suppressed} suprimido(s)"]);
    }

    private function find(string $ref): Campaign
    {
        return Campaign::where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where('code', $ref))->first() ?? throw ApiException::notFound();
    }

    private function automation(string $ref): Automation
    {
        return Automation::with('template')->where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where('code', $ref))->first() ?? throw ApiException::notFound();
    }
}
