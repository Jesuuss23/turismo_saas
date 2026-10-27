<?php

namespace App\Services\Collab;

use App\Models\Collab\ApprovalRequest;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;

/** SLA de la solicitud (§8 `approvals:sla-check`): semáforo, advertencia al 75 % y vencimiento con escalamiento al Manager (§12-2). */
class ApprovalSla
{
    public function __construct(
        private readonly ApprovalRouter $router,
        private readonly ApprovalRequests $requests,
        private readonly Notify $notify,
    ) {}

    /** `paused` (info), `overdue`, `warning` (≥ 75 % consumido), `ok`; null si la solicitud ya se resolvió. */
    public function state(ApprovalRequest $r): ?string
    {
        if (! $r->isLive()) {
            return null;
        }
        if ($r->status === 'info') {
            return 'paused';
        }
        if (! $r->due_at) {
            return 'ok';
        }
        if ($r->due_at->isPast()) {
            return 'overdue';
        }
        $start = $r->level_started_at ?? $r->created_at;
        $total = max(1, $start->diffInSeconds($r->due_at, true));
        $elapsed = $start->diffInSeconds(now(), true);

        return $elapsed / $total >= 0.75 ? 'warning' : 'ok';
    }

    /** @return array{warnings: int, overdue: int} */
    public function check(): array
    {
        $result = ['warnings' => 0, 'overdue' => 0];
        foreach (ApprovalRequest::where('status', 'pendiente')->whereNotNull('due_at')->whereNull('sla_overdue_at')->get() as $r) {
            $state = $this->state($r);
            if ($state === 'overdue') {
                $r->forceFill(['sla_overdue_at' => now(), 'sla_warned_at' => $r->sla_warned_at ?? now()])->saveQuietly();
                $this->requests->event($r, 'sla_vencido', null, 'SLA vencido el '.$r->due_at->format('d/m H:i'));
                $approvers = $this->router->eligibleUsers($r);
                foreach ($approvers->merge(CatalogRecipients::managers())->unique('id') as $user) {
                    $this->notify->push('aprobacion.sla_vencido', 'portal', $user, "SLA vencido · {$r->code}: {$r->title}", "Nivel {$r->current_level} sin decisión",
                        ['related_type' => 'approval', 'related_id' => $r->id]);
                }
                $result['overdue']++;
            } elseif ($state === 'warning' && $r->sla_warned_at === null) {
                $r->forceFill(['sla_warned_at' => now()])->saveQuietly();
                $this->requests->event($r, 'sla_advertencia', null, 'Vence el '.$r->due_at->format('d/m H:i'));
                foreach ($this->router->eligibleUsers($r) as $user) {
                    $this->notify->push('aprobacion.sla_advertencia', 'portal', $user, "SLA por vencer · {$r->code}: {$r->title}", 'Vence el '.$r->due_at->format('d/m H:i'),
                        ['related_type' => 'approval', 'related_id' => $r->id]);
                }
                $result['warnings']++;
            }
        }

        return $result;
    }
}
