<?php

namespace App\Services\Crm;

use App\Models\Crm\AfterSalesCase;
use App\Services\Reports\FileWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/** Constancia de la hoja de reclamación (LR-BR-05) en PDF; el contenido mínimo de la hoja queda por validar con asesoría legal (§12-1). */
class ClaimCertificate
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function pdf(AfterSalesCase $c): string
    {
        $consumer = $c->consumer ?? [];
        $guardian = $consumer['guardian'] ?? null;
        $r = $c->response;
        $rows = [
            ['Proveedor', trim(($this->tenant->tenant()?->legal_name ?? '').' · RUC '.($this->tenant->tenant()?->ruc ?? '—'))], ['Hoja N.°', $c->book_number], ['Sede', $c->sede?->name],
            ['Fecha de recepción', $c->received_at?->setTimezone('America/Lima')->format('d/m/Y H:i')],
            ['Tipo', ClaimBookPresenter::TYPE_LABELS[$c->type_code] ?? $c->type_code], ['Canal', ClaimBookService::CHANNELS[$c->channel] ?? $c->channel],
            ['Consumidor', $consumer['name'] ?? ''], ['Documento', ($consumer['doc_type'] ?? '').' '.($consumer['doc_number'] ?? '')],
            ['Domicilio', $consumer['address'] ?? ''], ['Correo', $consumer['email'] ?? ''], ['Teléfono', $consumer['phone'] ?? '—'],
        ];
        if ($guardian) {
            $rows[] = ['Padre, madre o apoderado', "{$guardian['name']} · {$guardian['doc_type']} {$guardian['doc_number']} ({$guardian['relation']})"];
        }
        $rows[] = ['Bien contratado', ($c->good['kind'] ?? '').': '.($c->good['description'] ?? '').(isset($c->good['amount']) && $c->good['amount'] ? " · {$c->good['currency']} {$c->good['amount']}" : '')];
        foreach ([['Detalle', $c->detail], ['Pedido', $c->request]] as [$label, $text]) {
            foreach ($this->chunks((string) $text) as $i => $chunk) {
                $rows[] = [$i === 0 ? $label : '', $chunk];
            }
        }
        $rows[] = ['Plazo de respuesta', "{$c->response_days} días hábiles · vence el ".$c->due_date?->format('d/m/Y')];
        if ($r) {
            $rows[] = ['Respuesta', (ClaimBookService::DECISIONS[$r['decision']] ?? $r['decision']).' · enviada el '.Carbon::parse($r['sent_at'])->setTimezone('America/Lima')->format('d/m/Y H:i')];
            foreach ($this->chunks((string) $r['text']) as $chunk) {
                $rows[] = ['', $chunk];
            }
        }
        $rows[] = ['Estado', ClaimBookService::STATUS_LABELS[$c->book_status] ?? $c->book_status];

        return FileWriter::pdf("Constancia de hoja de reclamación {$c->book_number}", ['Campo', 'Detalle'], $rows);
    }

    /** @return list<string> */
    private function chunks(string $text): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text === '' ? [''] : explode("\n", wordwrap($text, 80, "\n", true));
    }
}
