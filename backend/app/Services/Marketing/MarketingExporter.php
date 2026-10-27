<?php

namespace App\Services\Marketing;

use App\Models\Iam\User;
use App\Models\Marketing\Campaign;
use Illuminate\Support\Collection;

/** Exportación CSV de la lista de campañas (spec 14 §2) con sus métricas; mismos filtros que la tabla. */
class MarketingExporter
{
    public const RESOURCES = ['marketing.campaigns' => ['marketing.campaigns.manage']];

    public function __construct(private readonly MarketingPresenter $present) {}

    /** @return array{0: list<string>, 1: Collection<int, list<mixed>>} */
    public function rows(string $resource, User $user, array $filters = []): array
    {
        $rows = Campaign::with(['segment', 'template', 'owner'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->orderByDesc('created_at')->get();
        $metrics = $this->present->metrics($rows->pluck('id')->all());

        return [
            ['Código', 'Nombre', 'Canal', 'Segmento', 'Estado', 'Enviada', 'Enviados', 'Apertura %', 'CTR %', 'Reservas', 'Leads', 'Ingresos S/'],
            $rows->map(function (Campaign $c) use ($metrics) {
                $r = $this->present->campaignRow($c, $metrics);
                $m = $r['metrics'];

                return [$c->code, $c->name, $r['channel_label'], $c->segment?->code, $r['status_label'], $c->sent_at?->toIso8601String(), $m['sent'], $m['open_rate'], $m['ctr'],
                    $m['reservations'], $m['leads'], $m['revenue_pen']];
            }),
        ];
    }
}
