<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Finance\DocumentSeries;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceLine;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fiscal\FiscalGateway;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Facturación electrónica (PROC-050, FIN-BR-39…50): prellenado, validación fiscal, numeración por serie con `FOR UPDATE`,
 * emisión asíncrona vía {@see FiscalGateway}, consulta, corrección y reenvío, entrega y notas de crédito.
 */
class InvoiceService
{
    public const IGV = 0.18;

    private const RUC = '/^(10|15|17|20)\d{9}$/';

    private const DNI = '/^\d{8}$/';

    private const PASSPORT = '/^[A-Z0-9]{6,12}$/';

    public function __construct(
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly FxRates $fx,
        private readonly FiscalGateway $gateway,
    ) {}

    /** Documento del cliente en el vocabulario del comprobante (RUC, DNI, PASAPORTE, CE, SIN_DOC). */
    public static function docType(?string $type): string
    {
        $type = mb_strtoupper(trim((string) $type));

        return match (true) {
            $type === 'RUC', $type === 'DNI', $type === 'CE' => $type,
            str_starts_with($type, 'PAS') => 'PASAPORTE',
            str_starts_with($type, 'CARN') => 'CE',
            default => 'SIN_DOC',
        };
    }

    /** FIN-BR-43: importe de la CxC − Σ comprobantes vigentes de esa CxC (NC restan). */
    public function maxInvoiceable(Receivable $r): float
    {
        $invoiced = Invoice::where('receivable_id', $r->id)->whereIn('status', Invoice::COUNTED)->get()->sum(fn (Invoice $i) => $i->signedAmount());

        return max(0, Fin::r2((float) $r->amount - $invoiced));
    }

    /** `GET /receivables/{code}/invoice-draft` (FIN-BR-44/45). */
    public function draft(Receivable $r): array
    {
        $client = $r->client;
        $docType = self::docType($client?->doc_type);
        $max = $this->maxInvoiceable($r);
        $paid = (float) $r->paid_amount;
        $domiciled = $client ? $this->isPeru($client->country) : true;

        return ['receivable' => ['id' => $r->id, 'code' => $r->code, 'concept' => $r->concept, 'amount' => $r->amount, 'paid' => $r->paid_amount, 'status' => $r->status],
            'reservation' => $r->reservation?->code, 'client' => $client?->code, 'currency' => $r->currency,
            'doc_type' => $docType === 'RUC' ? Invoice::INVOICE : Invoice::RECEIPT,
            'customer_doc_type' => $docType, 'customer_doc_number' => (string) $client?->doc_number, 'customer_name' => (string) $client?->name,
            'customer_address' => trim(implode(', ', array_filter([$client?->city, $client?->country]))), 'domiciled' => $domiciled,
            'max' => Fin::money($max), 'amount' => Fin::money($paid > Fin::EPS ? min($paid, $max) : $max),
            'fx_rate' => $r->currency === 'PEN' ? null : (string) ($r->fx_rate ?? $this->fx->rate($r->currency)),
            'description' => "{$r->concept} — reserva {$r->reservation?->code}"];
    }

    /**
     * Validación fiscal en seco (`POST /invoices/validate`): checklist de §5.7 con el primer error como `error`.
     *
     * @return array{ok: bool, checks: list<array{key: string, label: string, ok: bool, message: ?string}>, error: ?array{code: string, message: string}, totals: array}
     */
    public function validate(array $data): array
    {
        $type = (string) ($data['doc_type'] ?? '');
        $ref = ! empty($data['ref_invoice_id']) ? Invoice::find($data['ref_invoice_id']) : null;
        $receivable = ! empty($data['receivable_id']) ? Receivable::find($data['receivable_id']) : $ref?->receivable;
        $docType = self::docType($data['customer_doc_type'] ?? null);
        $docNumber = mb_strtoupper(trim((string) ($data['customer_doc_number'] ?? '')));
        $amount = Fin::r2($data['amount'] ?? 0);
        $currency = $receivable?->currency ?? $ref?->currency ?? 'PEN';
        $checks = [];
        $add = function (string $key, string $label, ?array $error) use (&$checks) {
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => $error === null, 'message' => $error[1] ?? null, 'code' => $error[0] ?? null];
        };

        $add('type', 'Tipo de comprobante', isset(Invoice::TYPE_LABELS[$type]) && $type !== '08' ? null : ['FIN-BR-46', 'Tipo de comprobante no soportado (factura, boleta o nota de crédito).']);
        $refType = $type === Invoice::CREDIT_NOTE ? $ref?->doc_type : $type;
        $add('document', 'Documento del adquirente', match (true) {
            $docNumber === '' || $docType === 'SIN_DOC' => ['FIN-BR-41', 'Documento del adquirente obligatorio.'],
            $refType === Invoice::INVOICE && ($docType !== 'RUC' || ! preg_match(self::RUC, $docNumber)) => ['RT-10', 'Factura requiere RUC válido de 11 dígitos (Regla transversal 10).'],
            $refType === Invoice::RECEIPT && $docType === 'RUC' => ['FIN-BR-40', 'El adquirente tiene RUC: corresponde emitir factura.'],
            $refType === Invoice::RECEIPT && $docType === 'DNI' && ! preg_match(self::DNI, $docNumber) => ['FIN-BR-40', 'DNI inválido: 8 dígitos.'],
            $refType === Invoice::RECEIPT && in_array($docType, ['PASAPORTE', 'CE'], true) && ! preg_match(self::PASSPORT, $docNumber) => ['FIN-BR-40', 'Pasaporte/CE inválido: 6 a 12 caracteres alfanuméricos.'],
            default => null,
        });
        $add('name', 'Razón social / nombre y descripción', match (true) {
            trim((string) ($data['customer_name'] ?? '')) === '' => ['FIN-BR-42', 'Indique la razón social o el nombre del adquirente.'],
            trim((string) ($data['description'] ?? '')) === '' => ['FIN-BR-42', 'Indique la descripción del comprobante.'],
            default => null,
        });
        if ($type === Invoice::CREDIT_NOTE) {
            $available = $ref ? $this->creditable($ref) : 0;
            $add('reference', 'Comprobante que modifica', match (true) {
                ! $ref => ['FIN-BR-41', 'La nota de crédito debe referenciar un comprobante.'],
                $ref->isCreditNote() => ['FIN-BR-41', 'Una nota de crédito no puede modificar otra nota de crédito.'],
                $ref->status !== 'aceptado' => ['FIN-BR-41', "El comprobante {$ref->number} no está aceptado por el OSE."],
                ! empty($data['client_id']) && $data['client_id'] !== $ref->client_id => ['FIN-BR-41', 'La nota de crédito debe ser del mismo cliente del comprobante.'],
                empty($data['credit_note_reason_code']) || ! isset(Invoice::CREDIT_REASONS[$data['credit_note_reason_code']]) => ['FIN-BR-41', 'Seleccione el motivo de la nota de crédito (Catálogo 09).'],
                default => null,
            });
            $add('amount', 'Importe', match (true) {
                $amount <= 0 => ['FIN-BR-43', 'El importe debe ser mayor a 0.'],
                $ref && $amount > $available + Fin::EPS => ['FIN-BR-41', "El importe excede lo acreditable de {$ref->number} ({$ref->currency} ".Fin::money($available).').'],
                default => null,
            });
        } else {
            $max = $receivable ? $this->maxInvoiceable($receivable) : 0;
            $add('amount', 'Importe y máximo facturable', match (true) {
                ! $receivable => ['FIN-BR-43', 'Seleccione la cuenta por cobrar a facturar.'],
                $receivable->status === 'cancelada' => ['FIN-BR-43', "La CxC {$receivable->code} está cancelada."],
                $amount <= 0 => ['FIN-BR-43', 'El importe debe ser mayor a 0.'],
                $amount > $max + Fin::EPS => ['FIN-BR-43', "El importe excede el máximo facturable de {$receivable->code} ({$receivable->currency} ".Fin::money($max).').'],
                default => null,
            });
        }
        $fxRate = $currency === 'PEN' ? null : (float) ($data['fx_rate'] ?? 0);
        $add('fx', 'Tipo de cambio', $currency !== 'PEN' && $fxRate <= 0 ? ['FIN-BR-15', 'Indique el tipo de cambio del comprobante en USD.'] : null);
        $period = $this->clock->today()->format('Y-m');
        $add('period', 'Periodo contable abierto', $this->periodClosed($period)
            ? ['FIN-BR-48', "El periodo {$period} está cerrado: no se emiten comprobantes con fecha del periodo. Ajuste vía NC/ND en el periodo siguiente."] : null);

        $domiciled = $type === Invoice::CREDIT_NOTE && $ref ? $ref->customer_domiciled : filter_var($data['domiciled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $error = collect($checks)->firstWhere('ok', false);

        return ['ok' => $error === null, 'checks' => array_map(fn ($c) => array_diff_key($c, ['code' => 1]), $checks),
            'error' => $error ? ['code' => $error['code'], 'message' => $error['message']] : null,
            'totals' => $this->totals($amount, $domiciled, $currency) + ['currency' => $currency]];
    }

    /** `POST /invoices`: emite factura/boleta/NC → `pendiente_envio` y lo envía al OSE después del commit. */
    public function issue(array $data, User $actor): Invoice
    {
        $check = $this->validate($data);
        if (! $check['ok']) {
            throw ApiException::unprocessable($check['error']['code'], $check['error']['message'], ['checks' => $check['checks']]);
        }
        $type = $data['doc_type'];
        $ref = $type === Invoice::CREDIT_NOTE ? Invoice::findOrFail($data['ref_invoice_id']) : null;
        $receivable = $ref ? $ref->receivable : Receivable::findOrFail($data['receivable_id']);

        $invoice = DB::transaction(function () use ($data, $type, $ref, $receivable, $actor) {
            // Revalida bajo bloqueo el máximo facturable / acreditable (dos emisiones simultáneas sobre la misma CxC).
            $amount = Fin::r2($data['amount']);
            if ($ref) {
                Invoice::whereKey($ref->id)->lockForUpdate()->first();
                if ($amount > $this->creditable($ref) + Fin::EPS) {
                    throw ApiException::unprocessable('FIN-BR-41', "El importe excede lo acreditable de {$ref->number}.");
                }
            } elseif ($receivable) {
                Receivable::whereKey($receivable->id)->lockForUpdate()->first();
                if ($amount > $this->maxInvoiceable($receivable) + Fin::EPS) {
                    throw ApiException::unprocessable('FIN-BR-43', "El importe excede el máximo facturable de {$receivable->code}.");
                }
            }
            $seriesCode = match ($type) {
                Invoice::INVOICE => 'F001',
                Invoice::RECEIPT => 'B001',
                default => $ref->doc_type === Invoice::INVOICE ? 'FC01' : 'BC01',
            };
            $series = DocumentSeries::where('series', $seriesCode)->where('active', true)->lockForUpdate()->first()
                ?? throw ApiException::rule('FIN-BR-46', "La serie {$seriesCode} no está configurada.");
            $correlative = $series->next_number;
            $series->forceFill(['next_number' => $correlative + 1])->save();

            $reservation = $ref?->reservation ?? $receivable->reservation;
            $currency = $ref?->currency ?? $receivable->currency;
            $domiciled = $ref ? $ref->customer_domiciled : filter_var($data['domiciled'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $totals = $this->totals($amount, $domiciled, $currency);
            $fx = $currency === 'PEN' ? null : (string) $data['fx_rate'];
            $description = trim((string) $data['description']);
            $today = $this->clock->today();
            $invoice = Invoice::create([
                'number' => sprintf('%s-%08d', $seriesCode, $correlative), 'doc_type' => $type, 'series' => $seriesCode, 'correlative' => $correlative,
                'issue_date' => $today->toDateString(), 'period' => $today->format('Y-m'),
                'client_id' => $ref?->client_id ?? $reservation->client_id, 'reservation_id' => $reservation->id, 'receivable_id' => $receivable?->id,
                'customer_doc_type' => $ref?->customer_doc_type ?? self::docType($data['customer_doc_type']),
                'customer_doc_number' => $ref?->customer_doc_number ?? mb_strtoupper(trim((string) $data['customer_doc_number'])),
                'customer_name' => $ref?->customer_name ?? trim((string) $data['customer_name']),
                'customer_address' => $ref?->customer_address ?? ($data['customer_address'] ?? null), 'customer_domiciled' => $domiciled,
                'operation_type' => $totals['operation_type'], 'sunat_operation_code' => $totals['operation_type'] === 'gravada' ? '0101' : null,
                'currency' => $currency, 'fx_rate' => $fx, 'taxable_amount' => $totals['taxable'], 'igv_amount' => $totals['igv'], 'exempt_amount' => '0.00',
                'total_amount' => Fin::money($amount), 'amount_in_words' => AmountInWords::format($amount, $currency), 'description' => $description,
                'ref_invoice_id' => $ref?->id, 'credit_note_reason_code' => $ref ? $data['credit_note_reason_code'] : null,
                'credit_note_reason' => $ref ? (trim((string) ($data['credit_note_reason'] ?? '')) ?: Invoice::CREDIT_REASONS[$data['credit_note_reason_code']]) : null,
                'status' => 'pendiente_envio', 'fiscal_provider' => $this->gateway->providerName(), 'issued_by' => $actor->id,
            ]);
            InvoiceLine::create(['invoice_id' => $invoice->id, 'line_no' => 1, 'description' => $description, 'quantity' => 1, 'unit_code' => 'ZZ',
                'unit_value' => $totals['taxable'], 'igv_affectation' => $totals['operation_type'] === 'gravada' ? '10' : '40',
                'line_amount' => $totals['taxable'], 'igv_amount' => $totals['igv']]);
            Fin::history('invoice', $invoice->id, 'pendiente_envio', 'Validación fiscal OK · en cola de envío al OSE', $actor);
            $this->audit->log('Comprobante', $invoice->number, 'emitir', null,
                Invoice::TYPE_LABELS[$type]." {$currency} {$invoice->total_amount} · {$invoice->customer_name}".($ref ? " · modifica {$ref->number}" : '')
                .($receivable ? " · {$receivable->code}" : ''), actor: $actor);
            $this->gateway->queue($invoice);

            return $invoice;
        });

        return $invoice->refresh();
    }

    /** Consultar respuesta OSE: reenvía si quedó pendiente; consulta el CDR si está emitido. */
    public function statusCheck(Invoice $invoice, User $actor): Invoice
    {
        return match ($invoice->status) {
            'pendiente_envio' => $this->gateway->process($invoice->id, 'send', 1)->refresh(),
            'emitido' => $this->gateway->check($invoice, $actor),
            default => throw ApiException::rule('FIN-BR-49', "El comprobante {$invoice->number} ya tiene respuesta del OSE: ".mb_strtolower(Invoice::STATUS_LABELS[$invoice->status]).'.'),
        };
    }

    /** Corregir (TC, descripción) y reenviar un observado (§4.6, sujeto a §12 P-03). */
    public function resend(Invoice $invoice, array $data, User $actor): Invoice
    {
        if ($invoice->status !== 'observado') {
            throw ApiException::rule('FIN-BR-49', "Solo se corrige y reenvía un comprobante observado ({$invoice->number} está ".mb_strtolower(Invoice::STATUS_LABELS[$invoice->status]).').');
        }

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice->assertIfMatch(request());
            $before = "TC {$invoice->fx_rate} · {$invoice->description}";
            $description = trim((string) ($data['description'] ?? '')) ?: $invoice->description;
            $changes = ['description' => $description, 'status' => 'pendiente_envio'];
            if ($invoice->currency !== 'PEN' && ! empty($data['fx_rate'])) {
                $changes['fx_rate'] = (string) $data['fx_rate'];
            }
            $invoice->forceFill($changes)->save();
            $invoice->lines()->update(['description' => $description]);
            Fin::history('invoice', $invoice->id, 'pendiente_envio', 'Corregido y reenviado al OSE', $actor);
            $this->audit->log('Comprobante', $invoice->number, 'reenviar', $before, "TC {$invoice->fx_rate} · {$description}", actor: $actor);
            $this->gateway->queue($invoice, 'resend');

            return $invoice;
        })->refresh();
    }

    /** Entregar / reenviar al cliente (FIN-BR-47): email + portal. */
    public function deliver(Invoice $invoice, User $actor): Invoice
    {
        if ($invoice->status !== 'aceptado') {
            throw ApiException::rule('FIN-BR-47', 'Solo se entregan comprobantes aceptados por el OSE.');
        }
        $again = $invoice->delivered_at !== null;
        $invoice->forceFill(['delivered_at' => now()])->save();
        $subject = Invoice::TYPE_LABELS[$invoice->doc_type]." {$invoice->number} — {$invoice->currency} {$invoice->total_amount}";
        $context = ['client_id' => $invoice->client_id, 'related_type' => 'invoice', 'related_id' => $invoice->id];
        if ($email = $invoice->client?->email) {
            $this->notify->push('comprobante.emitido', 'email', $email, $subject, $invoice->description, $context);
            $this->notify->push('comprobante.emitido', 'portal', $email, $subject, $invoice->description, $context);
        }
        Fin::history('invoice', $invoice->id, 'aceptado', $again ? 'Reenviado al cliente (email + portal)' : 'Entregado al cliente (email + portal)', $actor);
        $this->audit->log('Comprobante', $invoice->number, 'entregar', null, ($again ? 'Reenviado' : 'Entregado').' a '.($invoice->client?->email ?? 'cliente sin email'), actor: $actor);

        return $invoice;
    }

    /**
     * NC por cancelación (spec 07 §12 P-10): acredita el reembolso contra el último comprobante aceptado de la reserva, pasando por la
     * misma validación fiscal y el OSE. Sin comprobante aceptado (o con el periodo cerrado) no hay NC que emitir: devuelve null.
     */
    public function creditNoteForCancellation(Reservation $reservation, string $amount, string $description, User $actor): ?Invoice
    {
        $candidates = Invoice::where('reservation_id', $reservation->id)->whereIn('doc_type', [Invoice::INVOICE, Invoice::RECEIPT])
            ->where('status', 'aceptado')->orderByDesc('issue_date')->orderByDesc('correlative')->get();
        $ref = $candidates->first(fn (Invoice $i) => $this->creditable($i) > Fin::EPS);
        if (! $ref) {
            return null;
        }
        $data = ['doc_type' => Invoice::CREDIT_NOTE, 'ref_invoice_id' => $ref->id, 'client_id' => $ref->client_id, 'customer_doc_type' => $ref->customer_doc_type,
            'customer_doc_number' => $ref->customer_doc_number, 'customer_name' => $ref->customer_name, 'amount' => Fin::money(min((float) $amount, $this->creditable($ref))),
            'fx_rate' => $ref->fx_rate, 'description' => $description, 'credit_note_reason_code' => '01'];
        if (! $this->validate($data)['ok']) {
            Log::warning('NC por cancelación omitida: no pasa la validación fiscal', ['reservation' => $reservation->code, 'error' => $this->validate($data)['error']]);

            return null;
        }

        return $this->issue($data, $actor);
    }

    /** Lo que aún puede acreditarse por NC sobre un comprobante. */
    public function creditable(Invoice $ref): float
    {
        $credited = (float) Invoice::where('ref_invoice_id', $ref->id)->where('doc_type', Invoice::CREDIT_NOTE)->whereIn('status', Invoice::COUNTED)->sum('total_amount');

        return max(0, Fin::r2((float) $ref->total_amount - $credited));
    }

    public function periodClosed(string $period): bool
    {
        return PeriodClose::where('period', $period)->where('status', 'cerrado')->exists();
    }

    /** FIN-BR-45: gravada con IGV 18 % incluido; no domiciliado → exportación sin IGV. */
    public function totals(float $amount, bool $domiciled, string $currency): array
    {
        $taxable = $domiciled ? Fin::r2($amount / (1 + self::IGV)) : Fin::r2($amount);

        return ['operation_type' => $domiciled ? 'gravada' : 'exportacion', 'taxable' => Fin::money($taxable), 'igv' => Fin::money($amount - $taxable),
            'total' => Fin::money($amount), 'in_words' => AmountInWords::format($amount, $currency)];
    }

    private function isPeru(?string $country): bool
    {
        return in_array(mb_strtolower(trim((string) $country)), ['perú', 'peru', 'pe', ''], true);
    }
}
