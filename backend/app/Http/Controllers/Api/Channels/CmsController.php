<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Channel\CmsContent;
use App\Models\Channel\CmsContentEvent;
use App\Models\Iam\User;
use App\Services\Channels\CmsService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** CMS del sitio (spec 08 §6.4, PROC-055) y entrega pública del contenido publicado (§6.4, CMS-BR-07). */
class CmsController extends Controller
{
    public function __construct(private readonly CmsService $cms) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->cms->list((array) $request->input('filter', []) + ['q' => $request->input('q')]);

        return response()->json(['data' => $rows->map(fn (CmsContent $c) => $this->row($c))->all(), 'meta' => ['counts' => $this->cms->counts(),
            'types' => CmsContent::TYPES, 'channels' => CmsContent::CHANNELS, 'statuses' => CmsContent::STATUS_LABELS, 'can_publish' => $this->cms->canPublish($request->user())]]);
    }

    public function store(Request $request): JsonResponse
    {
        $content = $this->cms->create($request->all(), $request->user(), $request->boolean('submit'));

        return response()->json(['data' => $this->detail($content, $request->user())], 201);
    }

    public function show(Request $request, string $content): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->find($content), $request->user())]);
    }

    public function update(Request $request, string $content): JsonResponse
    {
        $c = $this->find($content)->assertIfMatch($request);

        return response()->json(['data' => $this->detail($this->cms->update($c, $request->all(), $request->user()), $request->user())]);
    }

    public function transition(Request $request, string $content, string $action): JsonResponse
    {
        $c = $this->find($content)->assertIfMatch($request);
        $user = $request->user();
        $data = (array) $request->input('content', []);
        $c = match ($action) {
            'submit' => $this->cms->submit($c, $data, $user),
            'return' => $this->cms->giveBack($c, (string) $request->input('reason', ''), $user),
            'approve' => $this->cms->approve($c, $data, $user),
            'publish' => $this->cms->publish($c, $user),
            'unpublish' => $this->cms->unpublish($c, (string) $request->input('reason', ''), $user),
        };

        return response()->json(['data' => $this->detail($c, $user)]);
    }

    public function preview(Request $request, ?string $content = null): JsonResponse
    {
        return response()->json(['data' => $this->cms->preview($request->all(), $content ? $this->find($content) : null)]);
    }

    /** Público: contenido publicado por slug o ubicación, con caché invalidada al publicar/despublicar. */
    public function publicShow(TenantContext $tenant, string $slug): JsonResponse
    {
        $data = Cache::remember('cms:'.$tenant->id().':'.$slug, 300, fn () => $this->publicRow($this->cms->publicBySlug($slug)));

        return response()->json(['data' => $data]);
    }

    public function publicPlacement(TenantContext $tenant, string $placement): JsonResponse
    {
        $data = Cache::remember('cms:'.$tenant->id().':placement:'.$placement, 300, fn () => $this->cms->publicPlacement($placement)->map(fn ($c) => $this->publicRow($c))->all());

        return response()->json(['data' => $data]);
    }

    private function find(string $id): CmsContent
    {
        return CmsContent::where('id', preg_match('/^[0-9a-f-]{36}$/i', $id) ? $id : null)->orWhere('code', $id)->with('author:id,name')->first() ?? throw ApiException::notFound();
    }

    private function row(CmsContent $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'type' => $c->type, 'channel' => $c->channel, 'slug' => $c->slug, 'status' => $c->status,
            'status_label' => CmsContent::STATUS_LABELS[$c->status], 'author' => $c->author?->name, 'placement' => $c->placement,
            'updated_at' => $c->updated_at->toIso8601String(), 'published_at' => $c->published_at?->toIso8601String()];
    }

    /** Acciones por estado según permiso (CMS-BR-01/02). */
    private function detail(CmsContent $c, User $user): array
    {
        $pub = $this->cms->canPublish($user);
        $actions = match ($c->status) {
            'borrador', 'despublicado' => ['save', 'submit'],
            'en_revision' => $pub ? ['save', 'return', 'approve'] : [],
            'aprobado' => $pub ? ['save', 'publish'] : [],
            'publicado' => $pub ? ['save', 'unpublish'] : [],
            default => [],
        };

        return $this->row($c) + ['body' => $c->body, 'seo_title' => $c->seo_title, 'seo_description' => $c->seo_description,
            'valid_from' => $c->valid_from?->toDateString(), 'valid_to' => $c->valid_to?->toDateString(), 'lock_version' => $c->lock_version, 'actions' => $actions,
            'notice' => match (true) {
                $c->status === 'publicado' && ! $pub => 'El contenido está publicado: solicite a un editor que lo despublique para modificarlo.',
                in_array($c->status, ['en_revision', 'aprobado'], true) && ! $pub => 'En revisión por un editor: solo puede redactar y enviar a revisión.',
                default => null,
            },
            'events' => CmsContentEvent::where('content_id', $c->id)->with('user:id,name')->orderByDesc('occurred_at')->get()->map(fn (CmsContentEvent $e) => [
                'at' => $e->occurred_at->toIso8601String(), 'user' => $e->user?->name, 'from' => $e->from_status, 'to' => $e->to_status, 'message' => $e->message, 'reason' => $e->reason,
            ])->all()];
    }

    private function publicRow(CmsContent $c): array
    {
        return ['code' => $c->code, 'title' => $c->title, 'type' => $c->type, 'channel' => $c->channel, 'slug' => $c->slug, 'placement' => $c->placement,
            'html' => CmsService::render((string) $c->body), 'seo' => ['title' => $c->seo_title ?: $c->title, 'description' => $c->seo_description],
            'published_at' => $c->published_at?->toIso8601String()];
    }
}
