<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Channel\CmsContent;
use App\Models\Channel\CmsContentEvent;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CMS (spec 08 §4.3, PROC-055): borrador → en revisión → aprobado → publicado → despublicado, con bitácora, SEO y slug único.
 * Aprobar, publicar, despublicar y devolver exigen `channels.cms.publish` (CMS-BR-01).
 */
class CmsService
{
    public const PUBLISH = 'channels.cms.publish';

    public const FIELDS = ['title', 'type', 'channel', 'slug', 'body', 'seo_title', 'seo_description', 'placement', 'valid_from', 'valid_to'];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
    ) {}

    public function canPublish(User $user): bool
    {
        return $user->hasPermissionTo(self::PUBLISH);
    }

    public function list(array $filter): Collection
    {
        $q = trim((string) ($filter['q'] ?? ''));

        return CmsContent::with('author:id,name')
            ->when($filter['status'] ?? null, fn ($w, $s) => $w->where('status', $s))
            ->when($filter['type'] ?? null, fn ($w, $t) => $w->where('type', $t))
            ->when($filter['channel'] ?? null, fn ($w, $c) => $w->where('channel', $c))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('title', 'ilike', "%{$q}%")->orWhere('slug', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%")))
            ->orderByDesc('updated_at')->get();
    }

    public function counts(): array
    {
        $by = CmsContent::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return collect(CmsContent::STATUS_LABELS)->map(fn ($l, $s) => (int) ($by[$s] ?? 0))->all();
    }

    /** "Guardar" (nuevo → borrador) o "Enviar a revisión" directo (§4.3). */
    public function create(array $data, User $actor, bool $submit = false): CmsContent
    {
        $data = $this->validate($data);

        return DB::transaction(function () use ($data, $actor, $submit) {
            $content = CmsContent::create($data + ['code' => $this->sequences->next('cms_content'), 'status' => 'borrador', 'author_id' => $actor->id]);
            $this->event($content, $actor, null, 'borrador', 'Contenido creado');
            $this->audit->log('Contenido CMS', $content->code, 'crear', null, "{$content->type} · {$content->title} · /{$content->slug}", actor: $actor);

            return $submit ? $this->move($content, 'en_revision', $actor, 'Enviado a revisión') : $content;
        });
    }

    /** CMS-BR-02: editar publicado/en revisión/aprobado exige publicar; en publicado el cambio es inmediato. */
    public function update(CmsContent $content, array $data, User $actor): CmsContent
    {
        if (! $this->canPublish($actor) && ! in_array($content->status, CmsContent::FREE_EDIT, true)) {
            throw ApiException::forbidden('CMS-BR-02', $content->status === 'publicado'
                ? 'El contenido está publicado: solicite a un editor que lo despublique para modificarlo.'
                : 'La siguiente transición requiere el permiso channels.cms.publish: solo puede redactar y enviar a revisión.');
        }

        return DB::transaction(fn () => $this->save($content, $data, $actor));
    }

    public function submit(CmsContent $content, array $data, User $actor): CmsContent
    {
        if (! in_array($content->status, ['borrador', 'despublicado'], true)) {
            throw ApiException::rule('PROC-055', 'Solo un borrador o un contenido despublicado se envía a revisión.');
        }

        return DB::transaction(function () use ($content, $data, $actor) {
            $content = $data ? $this->save($content, $data, $actor) : $content;
            $content = $this->move($content, 'en_revision', $actor, 'Enviado a revisión');
            foreach (CatalogRecipients::managers() as $m) {
                $this->notify->push('cms.revision', 'portal', $m, "Contenido por revisar: {$content->title}", "{$content->code} · {$content->type}", ['related_type' => 'cms_content', 'related_id' => $content->id]);
            }

            return $content;
        });
    }

    /** CMS-BR-05: devolver exige motivo y no guarda ediciones pendientes. */
    public function giveBack(CmsContent $content, string $reason, User $actor): CmsContent
    {
        $this->requirePublish($actor);
        $reason = $this->reason($reason, 'devolver');
        $this->expect($content, ['en_revision'], 'devolver a borrador');

        return DB::transaction(function () use ($content, $reason, $actor) {
            $content = $this->move($content, 'borrador', $actor, "Devuelto a borrador — {$reason}", $reason);
            if ($content->author) {
                $this->notify->push('cms.devuelto', 'portal', $content->author, "Contenido devuelto: {$content->title}", $reason, ['related_type' => 'cms_content', 'related_id' => $content->id]);
            }

            return $content;
        });
    }

    public function approve(CmsContent $content, array $data, User $actor): CmsContent
    {
        $this->requirePublish($actor);
        $this->expect($content, ['en_revision'], 'aprobar');

        return DB::transaction(function () use ($content, $data, $actor) {
            $content = $data ? $this->save($content, $data, $actor) : $content;

            return $this->move($content, 'aprobado', $actor, 'Aprobado');
        });
    }

    /** CMS-BR-04: publicar exige título SEO. */
    public function publish(CmsContent $content, User $actor): CmsContent
    {
        $this->requirePublish($actor);
        $this->expect($content, ['aprobado'], 'publicar');
        if (trim((string) $content->seo_title) === '') {
            throw ApiException::invalid('seo_title', 'Complete el título SEO antes de publicar.');
        }

        return DB::transaction(function () use ($content, $actor) {
            $content->forceFill(['published_at' => now(), 'unpublished_at' => null]);
            $content = $this->move($content, 'publicado', $actor, 'Publicado');
            if ($content->author) {
                $this->notify->push('cms.publicado', 'portal', $content->author, "Contenido publicado: {$content->title}", "/{$content->slug} · {$content->channel}", ['related_type' => 'cms_content', 'related_id' => $content->id]);
            }

            return $content;
        });
    }

    public function unpublish(CmsContent $content, string $reason, User $actor): CmsContent
    {
        $this->requirePublish($actor);
        $reason = $this->reason($reason, 'despublicar');
        $this->expect($content, ['publicado'], 'despublicar');

        return DB::transaction(function () use ($content, $reason, $actor) {
            $content->forceFill(['unpublished_at' => now()]);

            return $this->move($content, 'despublicado', $actor, "Despublicado — {$reason}", $reason);
        });
    }

    /** Vista previa con datos no guardados: HTML saneado + SERP. */
    public function preview(array $data, ?CmsContent $content = null): array
    {
        $title = trim((string) ($data['title'] ?? $content?->title ?? ''));
        $slug = trim((string) ($data['slug'] ?? '')) ?: self::slugify($title);
        $seo = trim((string) ($data['seo_title'] ?? $content?->seo_title ?? '')) ?: $title;
        $host = (string) ($this->tenant->tenant()?->slug ?? 'sitio').'.'.config('turismo.tenant_base_domain');

        return ['html' => self::render((string) ($data['body'] ?? $content?->body ?? '')), 'serp' => [
            'title' => mb_substr($seo, 0, 60), 'url' => "https://{$host}/{$slug}",
            'description' => mb_substr(trim((string) ($data['seo_description'] ?? $content?->seo_description ?? '')) ?: mb_substr(strip_tags(self::render((string) ($data['body'] ?? ''))), 0, 160), 0, 160),
            'title_length' => mb_strlen($seo), 'description_length' => mb_strlen((string) ($data['seo_description'] ?? $content?->seo_description ?? '')),
        ]];
    }

    /** CMS-BR-07: solo publicado y dentro de vigencia. */
    public function publicBySlug(string $slug): CmsContent
    {
        return $this->visible()->where('slug', $slug)->first() ?? throw ApiException::notFound();
    }

    public function publicPlacement(string $placement): Collection
    {
        return $this->visible()->where('placement', $placement)->orderByDesc('published_at')->get();
    }

    /** Contenido publicado para un canal (portales): "Web / Portal" cuenta para ambos portales. */
    public function forChannel(array $channels): Collection
    {
        return $this->visible()->whereIn('channel', $channels)->orderByDesc('published_at')->get();
    }

    private function visible()
    {
        $today = $this->clock->today()->toDateString();

        return CmsContent::where('status', 'publicado')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today));
    }

    public static function render(string $body): string
    {
        $html = [];
        $list = false;
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            $text = e($line);
            if (str_starts_with($line, '- ')) {
                if (! $list) {
                    $html[] = '<ul>';
                    $list = true;
                }
                $html[] = '<li>'.e(substr($line, 2)).'</li>';

                continue;
            }
            if ($list) {
                $html[] = '</ul>';
                $list = false;
            }
            if ($line === '') {
                continue;
            }
            $html[] = str_starts_with($line, '## ') ? '<h2>'.e(substr($line, 3)).'</h2>' : "<p>{$text}</p>";
        }
        if ($list) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    /** CMS-BR-03: minúsculas, sin tildes, guiones, máx. 60. */
    public static function slugify(string $text): string
    {
        return trim(mb_substr(Str::slug(Str::ascii($text)), 0, 60), '-');
    }

    private function save(CmsContent $content, array $data, User $actor): CmsContent
    {
        $data = $this->validate($data + $content->only(self::FIELDS), $content);
        $before = $content->only(['title', 'slug', 'seo_title', 'channel']);
        $content->fill($data)->save();
        $changed = array_keys(array_diff_assoc(array_map('strval', $content->only(array_keys($before))), array_map('strval', $before)));
        $this->event($content, $actor, null, null, 'Cambios guardados'.($changed ? ' ('.implode(', ', $changed).')' : ''));
        $this->audit->log('Contenido CMS', $content->code, 'editar', implode(' · ', array_map('strval', $before)), "{$content->title} · /{$content->slug} · ".CmsContent::STATUS_LABELS[$content->status], actor: $actor);
        $this->flush($content);

        return $content->fresh();
    }

    private function move(CmsContent $content, string $to, User $actor, string $message, ?string $reason = null): CmsContent
    {
        $from = $content->status;
        $content->forceFill(['status' => $to])->save();
        $this->event($content, $actor, $from, $to, $message, $reason);
        $this->audit->log('Contenido CMS', $content->code, 'cambio_estado', CmsContent::STATUS_LABELS[$from], CmsContent::STATUS_LABELS[$to].($reason ? " · {$reason}" : ''), actor: $actor);
        $this->flush($content);

        return $content->fresh();
    }

    private function event(CmsContent $content, User $actor, ?string $from, ?string $to, string $message, ?string $reason = null): void
    {
        CmsContentEvent::create(['content_id' => $content->id, 'occurred_at' => now(), 'user_id' => $actor->id, 'from_status' => $from, 'to_status' => $to,
            'message' => mb_substr($message, 0, 300), 'reason' => $reason ? mb_substr($reason, 0, 300) : null]);
    }

    /** §8.1 `CmsContentStatusChanged`: invalida la caché pública. */
    private function flush(CmsContent $content): void
    {
        Cache::forget('cms:'.$content->tenant_id.':'.$content->slug);
        if ($content->placement) {
            Cache::forget('cms:'.$content->tenant_id.':placement:'.$content->placement);
        }
    }

    private function validate(array $data, ?CmsContent $current = null): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $data = array_map(fn ($v) => is_string($v) ? trim($v) : ($v instanceof DateTimeInterface ? $v->format('Y-m-d') : $v), $data);
        if (($data['title'] ?? '') === '') {
            throw ApiException::invalid('title', 'Indique el título.');
        }
        if (! in_array($data['type'] ?? null, CmsContent::TYPES, true)) {
            throw ApiException::invalid('type', 'Elija el tipo de contenido.');
        }
        if (! in_array($data['channel'] ?? null, CmsContent::CHANNELS, true)) {
            throw ApiException::invalid('channel', 'Elija el canal destino.');
        }
        $data['slug'] = self::slugify(($data['slug'] ?? '') !== '' ? $data['slug'] : $data['title']);
        if ($data['slug'] === '') {
            throw ApiException::invalid('slug', 'La URL no puede quedar vacía.');
        }
        if (CmsContent::where('slug', $data['slug'])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
            throw ApiException::invalid('slug', "La URL /{$data['slug']} ya está en uso.");
        }
        if (mb_strlen((string) ($data['seo_title'] ?? '')) > 60) {
            throw ApiException::invalid('seo_title', 'El título SEO admite hasta 60 caracteres.');
        }
        if (mb_strlen((string) ($data['seo_description'] ?? '')) > 160) {
            throw ApiException::invalid('seo_description', 'La meta descripción admite hasta 160 caracteres.');
        }
        if (! empty($data['valid_from']) && ! empty($data['valid_to']) && $data['valid_to'] < $data['valid_from']) {
            throw ApiException::invalid('valid_to', 'La vigencia "hasta" debe ser igual o posterior a "desde".');
        }
        $data['body'] = (string) ($data['body'] ?? '');
        foreach (['seo_title', 'seo_description', 'placement', 'valid_from', 'valid_to'] as $f) {
            $data[$f] = ($data[$f] ?? '') === '' ? null : $data[$f];
        }

        return $data;
    }

    private function requirePublish(User $actor): void
    {
        if (! $this->canPublish($actor)) {
            throw ApiException::forbidden('CMS-BR-01', 'Requiere permiso channels.cms.publish: solo puede redactar y enviar a revisión.');
        }
    }

    private function reason(string $reason, string $what): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', "Indique el motivo para {$what}.");
        }

        return mb_substr($reason, 0, 300);
    }

    private function expect(CmsContent $content, array $from, string $what): void
    {
        if (! in_array($content->status, $from, true)) {
            throw ApiException::rule('PROC-055', "No se puede {$what}: el contenido está ".mb_strtolower(CmsContent::STATUS_LABELS[$content->status]).'.');
        }
    }
}
