<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Iam\User;
use App\Models\Marketing\Consent;
use App\Models\Marketing\Segment;
use App\Models\Marketing\Template;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\ConsentService;
use App\Services\Marketing\ContactDirectory;
use App\Services\Marketing\MarketingPresenter;
use App\Services\Marketing\SegmentRules;
use App\Services\Marketing\SegmentService;
use App\Services\Marketing\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Spec 14 §6: contactos, segmentos, plantillas y consentimientos (`marketing.campaigns.manage`). */
class MarketingController extends Controller
{
    public function __construct(private readonly MarketingPresenter $present, private readonly ContactDirectory $contacts) {}

    public function contacts(Request $request): JsonResponse
    {
        $filters = (array) $request->input('filter', []);
        $data = $this->present->consents(['type' => $filters['type'] ?? null, 'channel' => $filters['channel'] ?? null, 'status' => $filters['consent'] ?? null, 'q' => $request->input('q')]);

        return response()->json(['data' => $data['data']]);
    }

    /** Opciones del editor de campañas y del constructor (catálogo de reglas, usuarios internos para pruebas, códigos y productos). */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'fields' => collect(SegmentRules::FIELDS)->map(fn ($f, $key) => ['key' => $key, 'label' => $f[0], 'ops' => $f[1], 'type' => $f[2], 'options' => $f[3]])->values(),
            'segments' => Segment::orderBy('name')->get(['id', 'code', 'name']),
            'templates' => Template::orderBy('code')->get()->map(fn ($t) => $this->present->template($t)),
            'promotions' => ProductPromotion::with('product:id,name')->whereNotNull('promo_code')->orderBy('promo_code')->get()
                ->map(fn ($p) => ['id' => $p->id, 'code' => $p->promo_code, 'value' => (string) $p->value, 'status' => $p->effectiveStatus(), 'date_to' => $p->date_to->toDateString(),
                    'product' => $p->product?->name]),
            'products' => Product::where('status', Product::ACTIVE)->orderBy('name')->get(['id', 'ref', 'name']),
            'test_users' => User::where('status', User::ACTIVE)->orderBy('name')->get()->reject(fn (User $u) => $u->isExternal())
                ->map(fn (User $u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'email' => $u->email, 'mobile' => ContactDirectory::mobile($u->phone)])->values(),
            'variables' => Template::VARIABLES, 'consent_sources' => ConsentService::SOURCES, 'revoke_sources' => ConsentService::REVOKE_SOURCES,
            'policy_versions' => ConsentService::POLICY_VERSIONS,
        ]]);
    }

    // ── Segmentos ─────────────────────────────────────────────────────

    public function segments(): JsonResponse
    {
        return response()->json(['data' => $this->present->segments(Segment::with('owner')->orderBy('code')->get())]);
    }

    public function segment(string $segment): JsonResponse
    {
        $s = $this->findSegment($segment);

        return response()->json(['data' => $this->present->segments(collect([$s]))[0] + ['history' => $this->present->history('Segmento', [$s->code])]]);
    }

    public function storeSegment(Request $request, SegmentService $segments): JsonResponse
    {
        $s = $segments->create($request->all(), $request->user());

        return response()->json(['data' => $this->present->segment($s), 'message' => "Segmento {$s->code} creado"], 201);
    }

    public function updateSegment(Request $request, SegmentService $segments, string $segment): JsonResponse
    {
        $s = $this->findSegment($segment)->assertIfMatch($request);
        $s = $segments->update($s, $request->all(), $request->user());

        return response()->json(['data' => $this->present->segment($s), 'message' => "Segmento {$s->code} guardado"]);
    }

    public function destroySegment(Request $request, SegmentService $segments, string $segment): JsonResponse
    {
        $s = $this->findSegment($segment);
        $segments->delete($s, $request->user());

        return response()->json(['data' => ['code' => $s->code], 'message' => "Segmento {$s->code} eliminado"]);
    }

    public function duplicateSegment(Request $request, SegmentService $segments, string $segment): JsonResponse
    {
        $copy = $segments->duplicate($this->findSegment($segment), $request->user());

        return response()->json(['data' => $this->present->segment($copy), 'message' => "Segmento {$copy->code} creado como copia"], 201);
    }

    /** POST /segments/preview: conteo en vivo `{matched, by_type, email, whatsapp, sample[10]}`. */
    public function preview(Request $request, SegmentRules $rules, AudienceResolver $audience): JsonResponse
    {
        $match = (string) $request->input('match', 'all');
        if (! in_array($match, ['all', 'any'], true)) {
            throw ApiException::invalid('match', 'La coincidencia debe ser TODAS o ALGUNA.');
        }
        $valid = $rules->validate($request->input('rules', []));

        return response()->json(['data' => $audience->preview($match, $valid) + ['rules_text' => $rules->describe($match, $valid)]]);
    }

    // ── Plantillas ────────────────────────────────────────────────────

    public function templates(): JsonResponse
    {
        return response()->json(['data' => Template::orderBy('code')->get()->map(fn ($t) => $this->present->template($t))]);
    }

    public function storeTemplate(Request $request, TemplateService $templates): JsonResponse
    {
        $t = $templates->create($request->all(), $request->user());

        return response()->json(['data' => $this->present->template($t), 'message' => "Plantilla {$t->code} creada".($t->channel === 'whatsapp' ? ' y enviada a revisión de Meta' : '')], 201);
    }

    public function updateTemplate(Request $request, TemplateService $templates, string $template): JsonResponse
    {
        $t = $this->findTemplate($template)->assertIfMatch($request);
        $t = $templates->update($t, $request->all(), $request->user());

        return response()->json(['data' => $this->present->template($t), 'message' => "Plantilla {$t->code} guardada · ".Template::STATUS_LABELS[$t->status]]);
    }

    /** Sandbox INT-04: «Registrar respuesta de Meta» (en producción llega por webhook). */
    public function reviewTemplate(Request $request, TemplateService $templates, string $template): JsonResponse
    {
        $t = $templates->review($this->findTemplate($template), (string) $request->input('status', 'aprobada'), $request->user());

        return response()->json(['data' => $this->present->template($t), 'message' => "Plantilla {$t->code}: ".Template::STATUS_LABELS[$t->status]]);
    }

    // ── Consentimientos ───────────────────────────────────────────────

    public function consents(Request $request): JsonResponse
    {
        $filters = (array) $request->input('filter', []);

        return response()->json($this->present->consents($filters + ['q' => $request->input('q')]));
    }

    /** Drawer «Gestionar» de un contacto (por `tipo:id`). */
    public function contactConsents(string $type, string $id): JsonResponse
    {
        $contact = $this->contacts->find($type, $id) ?? throw ApiException::notFound();

        return response()->json(['data' => $this->present->contactConsents($contact)]);
    }

    public function storeConsent(Request $request, ConsentService $consents): JsonResponse
    {
        $c = $consents->grant($request->all(), $request->user(), $request->ip());

        return response()->json(['data' => ['code' => $c->code, 'status' => $c->status, 'channel' => $c->channel],
            'message' => 'Consentimiento '.ConsentService::CHANNELS[$c->channel]." {$c->code} registrado"], 201);
    }

    public function revokeConsent(Request $request, ConsentService $consents, string $consent): JsonResponse
    {
        $c = $this->findConsent($consent);
        $c = $consents->revoke($c, (string) $request->input('source', 'Solicitud verbal'), $request->user(), null, $request->ip());

        return response()->json(['data' => ['code' => $c->code, 'status' => $c->status], 'message' => 'Baja de '.ConsentService::CHANNELS[$c->channel].' registrada']);
    }

    public function consentEvents(string $consent): JsonResponse
    {
        $c = $this->findConsent($consent)->load(['events.actor', 'events.campaign']);

        return response()->json(['data' => $c->events->map(fn ($e) => ['event' => $e->event, 'source' => $e->source, 'evidence' => $e->evidence,
            'policy_version' => $e->policy_version, 'campaign' => $e->campaign?->code, 'actor' => $e->actor?->name ?? 'Titular / sistema', 'ip' => $e->ip,
            'at' => $e->occurred_at->toIso8601String()])]);
    }

    private function findSegment(string $ref): Segment
    {
        return Segment::with('owner')->where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where('code', $ref))->first() ?? throw ApiException::notFound();
    }

    private function findTemplate(string $ref): Template
    {
        return Template::where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where('code', $ref))->first() ?? throw ApiException::notFound();
    }

    private function findConsent(string $ref): Consent
    {
        return Consent::where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where('code', $ref))->first() ?? throw ApiException::notFound();
    }
}
