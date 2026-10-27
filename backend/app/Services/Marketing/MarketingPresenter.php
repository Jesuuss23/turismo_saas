<?php

namespace App\Services\Marketing;

use App\Models\Iam\User;
use App\Models\Marketing\Attribution;
use App\Models\Marketing\Automation;
use App\Models\Marketing\Campaign;
use App\Models\Marketing\CampaignRecipient;
use App\Models\Marketing\CampaignSuppression;
use App\Models\Marketing\Consent;
use App\Models\Marketing\Segment;
use App\Models\Marketing\Template;
use App\Models\Sys\AuditEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Respuestas de la API de marketing (spec 14 §6): listados con métricas, detalle de campaña, automatizaciones con su cola y consentimientos. */
class MarketingPresenter
{
    public function __construct(
        private readonly ContactDirectory $contacts,
        private readonly SegmentRules $rules,
        private readonly AudienceResolver $audience,
        private readonly AutomationService $automations,
        private readonly CampaignService $campaigns,
        private readonly MessageRenderer $renderer,
    ) {}

    /** Métricas por campaña (una consulta agrupada). @return array<string, array> */
    public function metrics(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = CampaignRecipient::whereIn('campaign_id', $ids)->groupBy('campaign_id')->select('campaign_id',
            DB::raw('count(*) FILTER (WHERE sent_at IS NOT NULL) as sent'),
            DB::raw("count(*) FILTER (WHERE status = 'entregado' OR delivered_at IS NOT NULL) as delivered"),
            DB::raw('count(opened_at) as opened'), DB::raw('count(clicked_at) as clicked'), DB::raw('count(replied_at) as replied'),
            DB::raw("count(*) FILTER (WHERE status = 'rebotado') as bounced"), DB::raw("count(*) FILTER (WHERE status IN ('fallido','suprimido')) as failed"),
            DB::raw("count(*) FILTER (WHERE status = 'pendiente') as pending"), DB::raw('count(unsubscribed_at) as unsubscribed'))->get()->keyBy('campaign_id');
        $conv = Attribution::whereIn('campaign_id', $ids)->groupBy('campaign_id')->select('campaign_id',
            DB::raw("count(*) FILTER (WHERE kind = 'reservation') as reservations"), DB::raw("count(*) FILTER (WHERE kind = 'lead') as leads"),
            DB::raw("count(*) FILTER (WHERE kind = 'promo_use') as promo_uses"), DB::raw("coalesce(sum(revenue_pen) FILTER (WHERE kind = 'reservation'), 0) as revenue"))
            ->get()->keyBy('campaign_id');
        $out = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            $c = $conv[$id] ?? null;
            $sent = (int) ($r?->sent ?? 0);
            $delivered = (int) ($r?->delivered ?? 0);
            $base = $delivered ?: $sent;
            $out[$id] = ['sent' => $sent, 'delivered' => $delivered, 'opened' => (int) ($r?->opened ?? 0), 'clicked' => (int) ($r?->clicked ?? 0),
                'replied' => (int) ($r?->replied ?? 0), 'bounced' => (int) ($r?->bounced ?? 0), 'failed' => (int) ($r?->failed ?? 0), 'pending' => (int) ($r?->pending ?? 0),
                'unsubscribed' => (int) ($r?->unsubscribed ?? 0),
                'open_rate' => $base ? round(100 * (int) $r->opened / $base, 1) : null, 'ctr' => $base ? round(100 * (int) $r->clicked / $base, 1) : null,
                'reservations' => (int) ($c?->reservations ?? 0), 'leads' => (int) ($c?->leads ?? 0), 'promo_uses' => (int) ($c?->promo_uses ?? 0),
                'revenue_pen' => round((float) ($c?->revenue ?? 0), 2)];
        }

        return $out;
    }

    public function campaignRow(Campaign $c, array $metrics): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'channel' => $c->channel, 'channel_label' => Campaign::CHANNEL_LABELS[$c->channel],
            'segment' => ['code' => $c->segment?->code, 'name' => $c->segment?->name], 'template' => ['code' => $c->template?->code, 'name' => $c->template?->name],
            'status' => $c->status, 'status_label' => Campaign::STATUS_LABELS[$c->status], 'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'sent_at' => $c->sent_at?->toIso8601String(), 'promo_code' => $c->promo_code, 'owner' => $c->owner?->name,
            'metrics' => $metrics[$c->id] ?? $this->metrics([$c->id])[$c->id], 'lock_version' => $c->lock_version];
    }

    /** KPIs de la pestaña Campañas (§2). */
    public function kpis(): array
    {
        $recent = Campaign::whereIn('status', ['enviando', 'enviada'])->where('sent_at', '>=', now()->subDays(90))->pluck('id')->all();
        $m = collect($this->metrics($recent));
        $base = $m->sum(fn ($x) => $x['delivered'] ?: $x['sent']);
        $states = $this->contacts->consentStates();
        $contactable = fn (string $channel) => $this->contacts->all()->filter(fn ($c) => ($channel === 'email' ? $c['email'] : $c['mobile']) !== null
            && ($states["{$c['type']}:{$c['id']}"][$channel] ?? null) === 'otorgado')->count();

        return ['sent_90d' => count($recent), 'open_rate' => $base ? round(100 * $m->sum('opened') / $base, 1) : null,
            'ctr' => $base ? round(100 * $m->sum('clicked') / $base, 1) : null, 'revenue_pen' => round((float) Attribution::where('kind', 'reservation')->sum('revenue_pen'), 2),
            'contactable_email' => $contactable('email'), 'contactable_whatsapp' => $contactable('whatsapp')];
    }

    public function campaign(Campaign $c): array
    {
        $c->loadMissing(['segment', 'template', 'owner', 'promotion.product', 'tests.user']);
        $metrics = $this->metrics([$c->id]);
        $sent = in_array($c->status, ['enviando', 'enviada'], true);
        $audience = $sent ? null : $this->campaigns->audience($c);
        $sample = $audience ? ($audience['eligible']->first() ?? $audience['suppressed']->first()) : null;
        $recipients = CampaignRecipient::where('campaign_id', $c->id)->orderBy('name')->limit(300)->get();
        $suppressed = $sent ? CampaignSuppression::where('campaign_id', $c->id)->orderBy('reason')->orderBy('name')->get()
            ->map(fn ($s) => ['type' => $s->subject_type, 'name' => $s->name, 'reason' => $s->reason, 'reason_label' => CampaignSuppression::REASONS[$s->reason]])
            : $audience['suppressed']->map(fn ($s) => ['type' => $s['type'], 'code' => $s['code'], 'name' => $s['name'], 'reason' => $s['reason'], 'reason_label' => CampaignSuppression::REASONS[$s['reason']]]);

        return $this->campaignRow($c, $metrics) + [
            'subject' => $c->subject, 'body' => $c->body, 'utm' => $c->utm, 'completed_at' => $c->completed_at?->toIso8601String(), 'cancel_reason' => $c->cancel_reason,
            'template' => ['id' => $c->template->id, 'code' => $c->template->code, 'name' => $c->template->name, 'status' => $c->template->status,
                'status_label' => Template::STATUS_LABELS[$c->template->status], 'category' => $c->template->category],
            'segment' => ['id' => $c->segment->id, 'code' => $c->segment->code, 'name' => $c->segment->name, 'rules_text' => $this->rules->describe($c->segment->match, $c->segment->rules ?? [])],
            'promotion' => $c->promotion ? ['id' => $c->promotion->id, 'ref' => $c->promotion->ref, 'code' => $c->promotion->promo_code, 'value' => (string) $c->promotion->value,
                'date_to' => $c->promotion->date_to->toDateString(), 'status' => $c->promotion->effectiveStatus(), 'product' => $c->promotion->product?->name] : null,
            'tests' => $c->tests->map(fn ($t) => ['to' => $t->user?->name, 'address' => $t->to_address, 'sent_at' => $t->sent_at->toIso8601String(),
                'valid' => $t->invalidated_at === null])->values()->all(),
            'has_valid_test' => $c->tests->contains(fn ($t) => $t->invalidated_at === null),
            'snapshot' => $c->audience_snapshot,
            'audience' => $audience ? ['matched' => $audience['matched'], 'eligible' => $audience['eligible']->count()] + $audience['counts'] : null,
            'suppressed' => $suppressed->values()->all(),
            'recipients' => $recipients->map(fn (CampaignRecipient $r) => ['id' => $r->id, 'type' => $r->subject_type, 'name' => $r->name, 'address' => $r->address,
                'status' => $r->status, 'status_label' => CampaignRecipient::STATUS_LABELS[$r->status], 'sent_at' => $r->sent_at?->toIso8601String(),
                'opened_at' => $r->opened_at?->toIso8601String(), 'clicked_at' => $r->clicked_at?->toIso8601String(), 'unsubscribed_at' => $r->unsubscribed_at?->toIso8601String(),
                'error' => $r->error])->all(),
            'attribution' => Attribution::with(['reservation:id,code,title', 'lead:id,code,full_name', 'order:id,code'])->where('campaign_id', $c->id)->orderBy('attributed_at')->get()
                ->map(fn (Attribution $a) => ['kind' => $a->kind, 'code' => $a->reservation?->code ?? $a->lead?->code ?? $a->order?->code,
                    'label' => $a->reservation?->title ?? $a->lead?->full_name ?? 'Pedido web con código', 'revenue_pen' => $a->revenue_pen === null ? null : (string) $a->revenue_pen,
                    'at' => $a->attributed_at->toIso8601String()])->all(),
            'preview' => $sample ? ['contact' => $sample['name'], 'subject' => $this->renderer->render($c->subject, $sample, $c->channel, $c->promo_code, $c->id),
                'body' => $this->renderer->render($c->body, $sample, $c->channel, $c->promo_code, $c->id)] : null,
            'history' => $this->history('Campaña', [$c->code]),
            'can' => ['edit' => $c->status === 'borrador', 'test' => in_array($c->status, ['borrador', 'programada'], true), 'schedule' => $c->status === 'borrador',
                'unschedule' => $c->status === 'programada', 'send' => in_array($c->status, ['borrador', 'programada'], true), 'process' => $c->status === 'enviando',
                'cancel' => in_array($c->status, ['borrador', 'programada'], true)],
        ];
    }

    /** @param Collection<int, Segment> $segments */
    public function segments(Collection $segments): array
    {
        $usage = Campaign::whereIn('segment_id', $segments->pluck('id'))->get(['segment_id', 'code'])->groupBy('segment_id');

        return $segments->map(function (Segment $s) use ($usage) {
            $email = $this->audience->resolve('email', $s->match, $s->rules ?? []);
            $wa = $this->audience->resolve('whatsapp', $s->match, $s->rules ?? []);

            return $this->segment($s) + ['matched' => $email['matched'], 'contactable_email' => $email['eligible']->count(), 'contactable_whatsapp' => $wa['eligible']->count(),
                'campaigns' => ($usage[$s->id] ?? collect())->pluck('code')->all()];
        })->values()->all();
    }

    public function segment(Segment $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'description' => $s->description, 'match' => $s->match, 'rules' => $s->rules ?? [],
            'rules_text' => $this->rules->describe($s->match, $s->rules ?? []), 'owner' => $s->owner?->name, 'lock_version' => $s->lock_version,
            'updated_at' => $s->updated_at?->toIso8601String()];
    }

    public function template(Template $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'channel' => $t->channel, 'channel_label' => Campaign::CHANNEL_LABELS[$t->channel],
            'category' => $t->category, 'status' => $t->status, 'status_label' => Template::STATUS_LABELS[$t->status], 'usable' => $t->usable(),
            'provider_template_id' => $t->provider_template_id, 'subject' => $t->subject, 'body' => $t->body, 'language' => $t->language,
            'reviewed_at' => $t->reviewed_at?->toIso8601String(), 'lock_version' => $t->lock_version];
    }

    public function automation(Automation $a, bool $withQueue = true): array
    {
        $queue = $withQueue ? $this->automations->queue($a) : collect();

        return ['id' => $a->id, 'code' => $a->code, 'key' => $a->key, 'name' => $a->name, 'trigger' => $a->trigger_desc, 'delay' => $a->delay_desc, 'channel' => $a->channel,
            'channel_label' => Campaign::CHANNEL_LABELS[$a->channel], 'purpose' => $a->purpose, 'status' => $a->status, 'status_label' => Automation::STATUS_LABELS[$a->status],
            'template' => $this->template($a->template), 'sent_total' => $a->sent_total, 'suppressed_total' => $a->suppressed_total, 'conversions_total' => $a->conversions_total,
            'last_run_at' => $a->last_run_at?->toIso8601String(), 'lock_version' => $a->lock_version,
            'queue' => $queue->map(fn ($q) => ['name' => $q['name'], 'subject_type' => $q['subject_type'], 'ref_code' => $q['ref_code'], 'reason' => $q['reason'],
                'reason_label' => $q['reason'] ? CampaignSuppression::REASONS[$q['reason']] : null])->all(),
            'runs' => $a->runs()->with('actor')->limit(10)->get()->map(fn ($r) => ['started_at' => $r->started_at->toIso8601String(), 'queued' => $r->queued, 'sent' => $r->sent,
                'suppressed' => $r->suppressed, 'by' => $r->actor?->name ?? 'Sistema'])->all()];
    }

    /** Pestaña Consentimientos: todos los contactos con su estado por canal (§2). */
    public function consents(array $filters): array
    {
        $consents = Consent::get()->groupBy(fn ($c) => "{$c->subject_type}:{$c->subject_id}");
        $rows = $this->contacts->all()->map(function (array $c) use ($consents) {
            $mine = ($consents["{$c['type']}:{$c['id']}"] ?? collect())->keyBy('channel');
            $channel = fn (string $ch) => ($x = $mine[$ch] ?? null) ? ['code' => $x->code, 'status' => $x->status, 'granted_at' => $x->granted_at->toIso8601String(),
                'source' => $x->source, 'revoked_at' => $x->revoked_at?->toIso8601String(), 'revoke_source' => $x->revoke_source] : null;

            return ['type' => $c['type'], 'type_label' => $c['type_label'], 'id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'email' => $c['email'],
                'phone' => $c['phone'], 'mobile' => $c['mobile'], 'email_consent' => $channel('email'), 'whatsapp_consent' => $channel('whatsapp')];
        })->values();
        if (! empty($filters['type'])) {
            $rows = $rows->where('type', $filters['type'])->values();
        }
        if (! empty($filters['channel']) && ! empty($filters['status'])) {
            $key = "{$filters['channel']}_consent";
            $rows = $rows->filter(fn ($r) => $filters['status'] === 'sin' ? $r[$key] === null : ($r[$key]['status'] ?? null) === $filters['status'])->values();
        }
        if (! empty($filters['q'])) {
            $q = mb_strtolower((string) $filters['q']);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower("{$r['name']} {$r['email']} {$r['code']}"), $q))->values();
        }
        $all = Consent::get();

        return ['data' => $rows->all(), 'kpis' => [
            'email' => $all->where('channel', 'email')->where('status', 'otorgado')->count(), 'whatsapp' => $all->where('channel', 'whatsapp')->where('status', 'otorgado')->count(),
            'optouts_30d' => $all->where('status', 'revocado')->filter(fn ($c) => $c->revoked_at->greaterThanOrEqualTo(now()->subDays(30)))->count(),
            'without' => $this->contacts->all()->filter(fn ($c) => ! isset($consents["{$c['type']}:{$c['id']}"]))->count(),
        ]];
    }

    /** Drawer «Gestionar»: por canal y el historial unificado. */
    public function contactConsents(array $contact): array
    {
        $consents = Consent::with(['events.actor', 'events.campaign', 'revokeCampaign'])->where('subject_type', $contact['type'])->where('subject_id', $contact['id'])->get()->keyBy('channel');
        $channels = [];
        foreach (ConsentService::CHANNELS as $ch => $label) {
            $c = $consents[$ch] ?? null;
            $channels[$ch] = $c ? ['code' => $c->code, 'label' => $label, 'status' => $c->status, 'status_label' => Consent::STATUS_LABELS[$c->status], 'granted_at' => $c->granted_at->toIso8601String(),
                'source' => $c->source, 'evidence' => $c->evidence, 'policy_version' => $c->policy_version, 'revoked_at' => $c->revoked_at?->toIso8601String(),
                'revoke_source' => $c->revoke_source, 'revoke_campaign' => $c->revokeCampaign?->code, 'lock_version' => $c->lock_version] : ['label' => $label, 'status' => null];
        }
        $events = $consents->flatMap(fn ($c) => $c->events->map(fn ($e) => ['channel' => $c->channel, 'event' => $e->event, 'source' => $e->source, 'evidence' => $e->evidence,
            'policy_version' => $e->policy_version, 'campaign' => $e->campaign?->code, 'actor' => $e->actor?->name ?? 'Titular / sistema', 'at' => $e->occurred_at->toIso8601String()]))
            ->sortByDesc('at')->values()->all();

        return ['contact' => collect($contact)->only(['type', 'type_label', 'id', 'code', 'name', 'email', 'phone', 'mobile'])->all(), 'channels' => $channels, 'events' => $events];
    }

    public function history(string $entity, array $ids): array
    {
        $rows = AuditEvent::where('entity', $entity)->whereIn('entity_id', $ids)->orderByDesc('occurred_at')->limit(60)->get();
        $users = User::whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (AuditEvent $e) => ['entity' => $e->entity, 'entity_id' => $e->entity_id, 'action' => $e->action, 'before' => $e->before_value,
            'after' => $e->after_value, 'actor' => ['name' => $e->actor_id ? ($users[$e->actor_id] ?? '—') : 'Sistema'], 'at' => $e->occurred_at])->all();
    }
}
