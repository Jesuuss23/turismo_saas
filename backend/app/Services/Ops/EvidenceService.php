<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Iam\User;
use App\Models\Ops\Evidence;
use App\Models\Ops\Incident;
use App\Models\Ops\Order;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Evidencias (PROC-038): imagen o PDF asociada a la orden, a un servicio o a una incidencia (OPS-BR-70), deduplicada
 * por SHA-256 en la orden (OPS-BR-71 / MOB-BR-09) y con retiro lógico con motivo.
 * La subida es directa a la API (contenido base64); el almacenamiento usa el disco `turismo.evidence_disk` (S3 en producción).
 */
class EvidenceService
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{file_name: string, mime_type: string, content_base64: string, captured_at?: string|null, sha256?: string|null}  $upload
     * @return array{0: Evidence, 1: bool} evidencia y si ya existía (mismo hash)
     */
    public function store(array $upload, ?Order $order, ?BookedService $service, ?Incident $incident, User $actor, string $source = 'web', bool $offline = false, ?string $clientActionId = null): array
    {
        $order ??= $incident?->order_id ? Order::find($incident->order_id) : null;
        if (! $order && ! $incident) {
            throw ApiException::invalid('order_id', 'La evidencia debe asociarse a una orden o a una incidencia (OPS-BR-70).');
        }
        if ($service && $order && $service->reservation_id !== $order->reservation_id) {
            throw ApiException::invalid('reservation_service_id', 'El servicio no pertenece a la orden.');
        }
        $mime = (string) ($upload['mime_type'] ?? '');
        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            throw ApiException::invalid('mime_type', 'Solo se aceptan imágenes o PDF (OPS-BR-70).');
        }
        $content = base64_decode((string) ($upload['content_base64'] ?? ''), true);
        if ($content === false || $content === '') {
            throw ApiException::invalid('content_base64', 'El archivo está vacío o no es válido.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw ApiException::invalid('content_base64', 'El archivo supera 8 MB.');
        }
        $sha = hash('sha256', $content);
        if (! empty($upload['sha256']) && ! hash_equals(strtolower((string) $upload['sha256']), $sha)) {
            throw ApiException::invalid('sha256', 'El hash no coincide con el contenido (archivo dañado en la subida).');
        }
        if ($order && ($existing = Evidence::where('order_id', $order->id)->where('sha256', $sha)->whereNull('removed_at')->first())) {
            if ($incident && ! $existing->incident_id) {
                $existing->forceFill(['incident_id' => $incident->id])->save();
            }

            return [$existing, true];
        }
        $ext = $mime === 'application/pdf' ? 'pdf' : (Str::after($mime, 'image/') ?: 'jpg');
        $path = "tenants/{$this->tenant->id()}/ops/evidences/".Str::uuid7().".{$ext}";
        Storage::disk($this->disk())->put($path, $content);
        $evidence = Evidence::create([
            'order_id' => $order?->id, 'reservation_service_id' => $service?->id, 'incident_id' => $incident?->id,
            'file_name' => mb_substr(basename((string) ($upload['file_name'] ?? 'evidencia')), 0, 200), 'storage_path' => $path, 'mime_type' => $mime,
            'size_bytes' => strlen($content), 'sha256' => $sha, 'source' => $source,
            'captured_at' => ! empty($upload['captured_at']) ? Carbon::parse($upload['captured_at']) : now(), 'captured_offline' => $offline,
            'uploaded_by' => $actor->id, 'client_action_id' => $clientActionId,
        ]);
        $this->audit->log('Evidencia', $evidence->file_name, 'upload', null,
            ($order ? "Orden {$order->code}" : '').($service ? " · {$service->code}" : '').($incident ? " · {$incident->code}" : '').($offline ? ' · capturada offline' : ''), actor: $actor);
        if ($incident) {
            app(IncidentService::class)->log($incident, 'evidencia', "Evidencia adjunta: {$evidence->file_name}", $actor);
        }

        return [$evidence->fresh(), false];
    }

    public function remove(Evidence $evidence, string $reason, User $actor): Evidence
    {
        if ($evidence->removed_at) {
            throw ApiException::rule('OPS-BR-70', 'La evidencia ya fue retirada.');
        }
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo del retiro (OPS-BR-70).');
        }
        $evidence->forceFill(['removed_at' => now(), 'removed_by' => $actor->id, 'removed_reason' => trim($reason)])->save();
        $this->audit->log('Evidencia', $evidence->file_name, 'retirar', null, trim($reason), actor: $actor);

        return $evidence->fresh();
    }

    public function content(Evidence $evidence): ?string
    {
        return Storage::disk($this->disk())->get($evidence->storage_path);
    }

    private function disk(): string
    {
        return (string) config('turismo.evidence_disk', 'local');
    }
}
