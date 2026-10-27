<?php

namespace App\Services\Supplier;

use App\Exceptions\ApiException;
use App\Models\Catalog\Provider;
use App\Models\Finance\Payable;
use App\Models\Iam\User;
use App\Models\Supplier\SupplierInvoice;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Factura del proveedor sobre su CxP (spec 06 §4.4, SUP-BR-40…44) y su validación por Finanzas.
 * La subida es directa a la API (base64), como las evidencias; el archivo va al disco de documentos (S3 en producción).
 */
class SupplierInvoices
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MIME = ['application/pdf' => 'pdf', 'application/xml' => 'xml', 'text/xml' => 'xml', 'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip'];

    public const NUMBER = '/^[A-Z0-9]{4}-\d{1,8}$/';

    public function __construct(
        private readonly SupplierRecipients $recipients,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function payableOf(Provider $provider, string $id): Payable
    {
        return Payable::whereKey($id)->where('provider_id', $provider->id)->where('status', '<>', Payable::CANCELLED)->first() ?? throw ApiException::notFound();
    }

    /** @return array{0: SupplierInvoice, 1: list<string>} factura y advertencias */
    public function upload(Provider $provider, Payable $payable, array $data, User $actor): array
    {
        $number = mb_strtoupper(trim((string) ($data['number'] ?? '')));
        if (! preg_match(self::NUMBER, $number)) {
            throw ApiException::invalid('number', 'Use el formato serie-correlativo, por ejemplo F001-000123 (SUP-BR-40).');
        }
        if (empty($data['issue_date'])) {
            throw ApiException::invalid('issue_date', 'Indique la fecha de emisión.');
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ApiException::invalid('amount', 'El importe debe ser mayor que cero.');
        }
        $file = $data['file'] ?? null;
        $content = is_array($file) ? base64_decode((string) ($file['content_base64'] ?? ''), true) : false;
        if (! is_array($file) || $content === false || $content === '') {
            throw ApiException::invalid('file', 'Adjunte el comprobante en PDF, XML o ZIP (SUP-BR-40).');
        }
        $mime = (string) ($file['mime_type'] ?? '');
        $name = mb_substr(basename((string) ($file['file_name'] ?? 'factura')), 0, 200);
        $ext = self::MIME[$mime] ?? (in_array($e = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf', 'xml', 'zip'], true) ? $e : null);
        if (! $ext) {
            throw ApiException::invalid('file', 'Solo se aceptan archivos PDF, XML o ZIP.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw ApiException::invalid('file', 'El archivo supera 8 MB.');
        }

        return DB::transaction(function () use ($provider, $payable, $data, $actor, $number, $amount, $content, $name, $ext, $mime) {
            $payable = Payable::whereKey($payable->id)->lockForUpdate()->firstOrFail();
            if ($payable->status === Payable::PAID) {
                throw ApiException::rule('SUP-BR-42', "El documento {$payable->code} ya está pagado: no requiere factura.");
            }
            if ($payable->activeInvoice()->exists()) {
                throw ApiException::rule('SUP-BR-42', "El documento {$payable->code} ya tiene una factura registrada.");
            }
            if (SupplierInvoice::withTrashed()->where('provider_id', $provider->id)->where('number', $number)->exists()) {
                throw ApiException::rule('SUP-BR-40', "La factura {$number} ya fue registrada.");
            }
            $difference = round($amount - (float) $payable->balance(), 2);
            $path = "tenants/{$this->tenant->id()}/supplier/invoices/".Str::uuid7().".{$ext}";
            Storage::disk($this->disk())->put($path, $content);
            try {
                $invoice = SupplierInvoice::create([
                    'payable_id' => $payable->id, 'provider_id' => $provider->id, 'number' => $number, 'issue_date' => $data['issue_date'],
                    'amount' => $amount, 'currency' => $payable->currency, 'amount_difference' => $difference, 'storage_path' => $path,
                    'mime_type' => $mime ?: "application/{$ext}", 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
                    'original_file_name' => $name, 'status' => 'registrada', 'uploaded_by' => $actor->id, 'uploaded_at' => now(),
                ]);
            } catch (QueryException $e) {
                Storage::disk($this->disk())->delete($path);
                throw str_contains($e->getMessage(), 'supplier_invoices_active_unique')
                    ? ApiException::rule('SUP-BR-42', "El documento {$payable->code} ya tiene una factura registrada.") : $e;
            }
            $this->audit->log('Cuenta por pagar', $payable->code, 'factura_proveedor', null, "{$number} ({$name})", actor: $actor);
            $warnings = abs($difference) > 0.01
                ? ["El importe difiere del saldo en {$payable->currency} ".number_format($difference, 2, '.', '').': Finanzas revisará la diferencia (SUP-BR-41).'] : [];
            $this->recipients->toRoles(['finance'], 'proveedor.factura', "Factura {$number} de {$provider->name}",
                "{$payable->code} · {$payable->currency} {$amount}".($warnings ? ' · con diferencia' : ''), ['related_type' => 'fin_payable', 'related_id' => $payable->id]);

            return [$invoice, $warnings];
        });
    }

    /** Finanzas: `registrada|observada → validada`, `registrada → observada`, `registrada|observada → rechazada` (nota obligatoria salvo validar). */
    public function review(SupplierInvoice $invoice, string $action, ?string $note, User $actor): SupplierInvoice
    {
        $to = ['validate' => 'validada', 'observe' => 'observada', 'reject' => 'rechazada'][$action];
        $from = ['validate' => ['registrada', 'observada'], 'observe' => ['registrada'], 'reject' => ['registrada', 'observada']][$action];
        if (! in_array($invoice->status, $from, true)) {
            throw ApiException::rule('SUP-BR-43', "La factura {$invoice->number} está ".mb_strtolower(SupplierInvoice::STATUS_LABELS[$invoice->status]).'.');
        }
        $note = trim((string) $note);
        if ($to !== 'validada' && $note === '') {
            throw ApiException::invalid('note', 'Indique el motivo para el proveedor.');
        }
        $before = SupplierInvoice::STATUS_LABELS[$invoice->status];
        $invoice->forceFill(['status' => $to, 'validated_by' => $actor->id, 'validated_at' => now(), 'validation_note' => $note ?: null])->save();
        $payable = $invoice->payable;
        $this->audit->log('Cuenta por pagar', $payable->code, 'factura_'.$to, $before, SupplierInvoice::STATUS_LABELS[$to]." · {$invoice->number}".($note ? " · {$note}" : ''), actor: $actor);
        $this->recipients->toProvider(Provider::withTrashed()->findOrFail($invoice->provider_id), 'supplier.payables.view', 'proveedor.factura_'.$to,
            "Factura {$invoice->number} ".mb_strtolower(SupplierInvoice::STATUS_LABELS[$to]), $note ?: "{$payable->code}: la agencia validó su factura.",
            ['related_type' => 'fin_payable', 'related_id' => $payable->id]);

        return $invoice->fresh();
    }

    public function content(SupplierInvoice $invoice): ?string
    {
        return Storage::disk($this->disk())->get($invoice->storage_path);
    }

    private function disk(): string
    {
        return (string) config('turismo.evidence_disk', 'local');
    }
}
