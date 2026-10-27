<?php

namespace App\Services\Marketing;

use App\Models\Bookings\Reservation;
use App\Models\Channel\EcomOrder;
use App\Models\Crm\Lead;
use App\Models\Crm\SalesTargetPolicy;
use App\Models\Marketing\Attribution;
use App\Models\Marketing\Campaign;
use App\Models\Marketing\CampaignRecipient;
use App\Models\Marketing\Subscriber;
use App\Services\Crm\CrmClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Atribución last-touch (MKT-BR-10, job RecomputeAttribution): read model `mkt_attributions` recalculado completo. Una conversión se
 * atribuye a una sola campaña: la última enviada al contacto antes de la conversión, dentro de la ventana (30 días por política).
 */
class AttributionService
{
    public function __construct(private readonly CrmClock $clock) {}

    /** @return array{reservation: int, lead: int, promo_use: int} */
    public function recompute(): array
    {
        $window = (int) (SalesTargetPolicy::value('attribution_window_days') ?? 30);
        $campaigns = Campaign::whereIn('status', ['enviando', 'enviada'])->whereNotNull('sent_at')->orderBy('sent_at')->get()->keyBy('id');
        $rows = collect();
        if ($campaigns->isNotEmpty()) {
            $touches = $this->touches($campaigns);
            $rows = $rows->concat($this->reservations($touches, $window))->concat($this->leads($campaigns, $window))->concat($this->promoUses($campaigns, $window));
        }
        DB::transaction(function () use ($rows) {
            Attribution::query()->delete();
            foreach ($rows as $row) {
                Attribution::create($row);
            }
        });

        return ['reservation' => $rows->where('kind', 'reservation')->count(), 'lead' => $rows->where('kind', 'lead')->count(), 'promo_use' => $rows->where('kind', 'promo_use')->count()];
    }

    /**
     * Contactos alcanzados por cliente: cada destinatario se lleva al cliente que es o en que se convirtió (lead → cliente, suscriptor → cliente).
     *
     * @return Collection<string, Collection<int, array{campaign: Campaign, sent_at: Carbon, subject_type: string, subject_id: string}>> client_id → toques
     */
    private function touches(Collection $campaigns): Collection
    {
        $leadClient = Lead::withTrashed()->whereNotNull('converted_client_id')->pluck('converted_client_id', 'id');
        $subscriberClient = Subscriber::withTrashed()->whereNotNull('client_id')->pluck('client_id', 'id');

        return CampaignRecipient::whereIn('campaign_id', $campaigns->keys())->whereNotNull('sent_at')->whereIn('status', ['enviado', 'entregado'])->get()
            ->map(fn (CampaignRecipient $r) => [
                'client_id' => match ($r->subject_type) {
                    'client' => $r->subject_id, 'lead' => $leadClient[$r->subject_id] ?? null, default => $subscriberClient[$r->subject_id] ?? null
                },
                'campaign' => $campaigns[$r->campaign_id], 'sent_at' => $r->sent_at, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
            ])->filter(fn ($t) => $t['client_id'] !== null)->groupBy('client_id');
    }

    /** Reservas (no borrador/cancelada) creadas en (envío, envío + ventana], sin cotización previa al envío; ingreso en PEN con el TC de la reserva. */
    private function reservations(Collection $touches, int $window): Collection
    {
        $out = collect();
        $reservations = Reservation::with('quote:id,created_at')->whereIn('client_id', $touches->keys())->whereNotIn('status', [Reservation::DRAFT, Reservation::CANCELLED])->get();
        foreach ($reservations as $r) {
            $last = $touches[$r->client_id]->filter(fn ($t) => $t['sent_at']->lessThan($r->created_at))->sortByDesc('sent_at')->first();
            if (! $last || $r->created_at->greaterThan($last['sent_at']->copy()->addDays($window))) {
                continue;
            }
            if ($r->quote && $r->quote->created_at && $r->quote->created_at->lessThan($last['sent_at'])) {
                continue; // Negocio ya iniciado antes del envío (p. ej. RES-2026-000348): no se atribuye.
            }
            $out->push(['campaign_id' => $last['campaign']->id, 'kind' => 'reservation', 'reservation_id' => $r->id, 'subject_type' => $last['subject_type'],
                'subject_id' => $last['subject_id'], 'revenue_pen' => round((float) $r->total_amount * ($r->currency === 'PEN' ? 1 : (float) $r->fx_rate), 2), 'attributed_at' => $r->created_at]);
        }

        return $out;
    }

    /** Leads nuevos cuyo email coincide con un destinatario de una campaña de email enviada antes, dentro de la ventana. */
    private function leads(Collection $campaigns, int $window): Collection
    {
        $byEmail = CampaignRecipient::whereIn('campaign_id', $campaigns->where('channel', 'email')->keys())->whereNotNull('sent_at')->get()
            ->groupBy(fn ($r) => mb_strtolower($r->address));
        $out = collect();
        foreach (Lead::withTrashed()->whereNotNull('email')->where('created_at', '>=', $campaigns->min('sent_at'))->get() as $l) {
            $last = ($byEmail[mb_strtolower(trim($l->email))] ?? collect())->filter(fn ($r) => $r->sent_at->lessThan($l->created_at)
                && ! ($r->subject_type === 'lead' && $r->subject_id === $l->id))->sortByDesc('sent_at')->first();
            if ($last && $l->created_at->lessThanOrEqualTo($last->sent_at->copy()->addDays($window))) {
                $out->push(['campaign_id' => $last->campaign_id, 'kind' => 'lead', 'lead_id' => $l->id, 'subject_type' => $last->subject_type, 'subject_id' => $last->subject_id,
                    'attributed_at' => $l->created_at]);
            }
        }

        return $out;
    }

    /** Usos del código de la campaña en pedidos web dentro de la ventana. */
    private function promoUses(Collection $campaigns, int $window): Collection
    {
        $withCode = $campaigns->filter(fn ($c) => $c->promo_code);
        if ($withCode->isEmpty()) {
            return collect();
        }
        $out = collect();
        foreach (EcomOrder::whereIn('promo_code', $withCode->pluck('promo_code')->unique())->get() as $o) {
            $c = $withCode->filter(fn ($c) => $c->promo_code === $o->promo_code && $c->sent_at->lessThan($o->ordered_at)
                && $o->ordered_at->lessThanOrEqualTo($c->sent_at->copy()->addDays($window)))->sortByDesc('sent_at')->first();
            if ($c) {
                $out->push(['campaign_id' => $c->id, 'kind' => 'promo_use', 'ecom_order_id' => $o->id, 'revenue_pen' => $this->clock->toPen((float) $o->total, $o->currency),
                    'attributed_at' => $o->ordered_at]);
            }
        }

        return $out;
    }
}
