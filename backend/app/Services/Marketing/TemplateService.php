<?php

namespace App\Services\Marketing;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Models\Marketing\Template;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/**
 * Plantillas (spec 14 §4.3, MKT-BR-02/03): email siempre activa y con `{{enlace_baja}}` si es comercial; WhatsApp con nombre en formato
 * Meta, «Responde BAJA» si es comercial, va a revisión al crearla o al editar su contenido y se aprueba o rechaza por webhook.
 */
class TemplateService
{
    public const CATEGORIES = ['MARKETING' => 'Marketing', 'UTILITY' => 'Utilidad', 'SERVICIO' => 'Servicio'];

    public function __construct(private readonly SequenceService $sequences, private readonly AuditLogger $audit) {}

    public function create(array $data, User $actor): Template
    {
        $fields = $this->fields($data, null);

        return DB::transaction(function () use ($fields, $actor) {
            $t = Template::create($fields + ['code' => $this->sequences->next('mkt_template'),
                'status' => $fields['channel'] === 'email' ? 'activa' : 'en_revision',
                'provider_template_id' => $fields['channel'] === 'whatsapp' ? 'wa_tpl_'.$fields['name'] : null]);
            $this->audit->log('Plantilla', $t->code, 'crear', null, "{$t->name} · ".Template::STATUS_LABELS[$t->status], actor: $actor);

            return $t;
        });
    }

    /** Editar el contenido o la categoría de una WhatsApp aprobada la devuelve a revisión de Meta. */
    public function update(Template $template, array $data, User $actor): Template
    {
        $fields = $this->fields($data + $template->only(['name', 'category', 'subject', 'body', 'language']), $template);
        $before = Template::STATUS_LABELS[$template->status];
        $contentChanged = $fields['body'] !== $template->body || $fields['category'] !== $template->category || $fields['name'] !== $template->name;
        if ($template->channel === 'whatsapp' && $contentChanged) {
            $fields += ['status' => 'en_revision', 'reviewed_at' => null];
        }
        $template->forceFill($fields)->save();
        $this->audit->log('Plantilla', $template->code, 'editar', $before, Template::STATUS_LABELS[$template->status], actor: $actor);

        return $template;
    }

    /** Respuesta de Meta (webhook o «Registrar respuesta de Meta» en sandbox). */
    public function review(Template $template, string $status, ?User $actor): Template
    {
        if ($template->channel !== 'whatsapp' || ! in_array($status, ['aprobada', 'rechazada'], true)) {
            throw ApiException::invalid('status', 'Solo las plantillas de WhatsApp reciben respuesta de Meta (aprobada o rechazada).');
        }
        if ($template->status === $status) {
            return $template;
        }
        $before = Template::STATUS_LABELS[$template->status];
        $template->forceFill(['status' => $status, 'reviewed_at' => now()])->save();
        $this->audit->log('Plantilla', $template->code, 'aprobacion_meta', $before, Template::STATUS_LABELS[$status], actor: $actor, system: $actor === null);

        return $template;
    }

    private function fields(array $data, ?Template $current): array
    {
        $channel = $current?->channel ?? (string) ($data['channel'] ?? '');
        if (! in_array($channel, ['email', 'whatsapp'], true)) {
            throw ApiException::invalid('channel', 'Elija el canal: email o WhatsApp.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw ApiException::invalid('name', 'Indique el nombre de la plantilla.');
        }
        if ($channel === 'whatsapp' && ! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw ApiException::invalid('name', 'En WhatsApp el nombre va en minúsculas, números y guion bajo (formato Meta).');
        }
        $category = (string) ($data['category'] ?? '');
        if (! isset(self::CATEGORIES[$category])) {
            throw ApiException::invalid('category', 'Elija la categoría: MARKETING, UTILITY o SERVICIO.');
        }
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            throw ApiException::invalid('body', 'Escriba el contenido de la plantilla.');
        }
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $body.' '.($data['subject'] ?? ''), $m);
        $unknown = array_diff($m[1], Template::VARIABLES);
        if ($unknown) {
            throw ApiException::invalid('body', 'Variables no permitidas: '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', $unknown)));
        }
        $subject = trim((string) ($data['subject'] ?? ''));
        if ($channel === 'email' && $subject === '') {
            throw ApiException::invalid('subject', 'Indique el asunto del correo.');
        }
        if ($category === 'MARKETING' && $channel === 'email' && ! str_contains($body, '{{enlace_baja}}')) {
            throw ApiException::unprocessable('MKT-BR-02', 'Las plantillas comerciales de email deben incluir {{enlace_baja}}.');
        }
        if ($category === 'MARKETING' && $channel === 'whatsapp' && ! str_contains(mb_strtoupper($body), 'BAJA')) {
            throw ApiException::unprocessable('MKT-BR-02', 'Las plantillas comerciales de WhatsApp deben indicar «Responde BAJA».');
        }

        return ['name' => $name, 'channel' => $channel, 'category' => $category, 'subject' => $channel === 'email' ? mb_substr($subject, 0, 200) : null,
            'body' => $body, 'language' => (string) ($data['language'] ?? 'es') ?: 'es'];
    }
}
