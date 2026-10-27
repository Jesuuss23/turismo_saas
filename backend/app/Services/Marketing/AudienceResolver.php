<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CampaignRecipient;
use App\Services\Crm\CrmClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Audiencia de un segmento para un canal (MKT-BR-01/02/06/14): coincidentes → supresión con motivo en este orden de prioridad:
 * baja (`optout`, prevalece sobre todo), sin dato (`nodata`), sin consentimiento (`consent`) y fatiga 72 h (`fatigue`).
 * Con `$at` en el pasado el consentimiento se reconstruye desde el historial (evaluación histórica).
 */
class AudienceResolver
{
    public const FATIGUE_HOURS = 72;

    public const REASONS = ['optout', 'nodata', 'consent', 'fatigue'];

    public function __construct(
        private readonly ContactDirectory $contacts,
        private readonly SegmentRules $rules,
        private readonly CrmClock $clock,
    ) {}

    /**
     * @return array{matched: int, by_type: array<string, int>, eligible: Collection<int, array>, suppressed: Collection<int, array>,
     *     counts: array<string, int>, at: string}
     */
    public function resolve(string $channel, string $match, array $rules, ?Carbon $at = null, ?string $excludeCampaignId = null): array
    {
        $historical = $at !== null && $at->lessThan(now()->subMinute());
        $at ??= $this->clock->now();
        $states = $this->contacts->consentStates($historical ? $at : null);
        $matched = $this->contacts->all()->filter(fn (array $c) => $this->rules->matches($c, $match, $rules, $states["{$c['type']}:{$c['id']}"] ?? [], $at))->values();
        $recent = $this->recentlyContacted($at, $excludeCampaignId);

        $eligible = collect();
        $suppressed = collect();
        foreach ($matched as $c) {
            $key = "{$c['type']}:{$c['id']}";
            $state = $states[$key][$channel] ?? null;
            $reason = match (true) {
                $state === 'revocado' => 'optout',
                ($channel === 'email' ? $c['email'] : $c['mobile']) === null => 'nodata',
                $state !== 'otorgado' => 'consent',
                isset($recent[$key]) => 'fatigue',
                default => null,
            };
            $row = $c + ['consent' => $states[$key] ?? [], 'address' => $channel === 'email' ? $c['email'] : $c['mobile']];
            $reason === null ? $eligible->push($row) : $suppressed->push($row + ['reason' => $reason]);
        }
        $counts = array_fill_keys(self::REASONS, 0);
        foreach ($suppressed as $s) {
            $counts[$s['reason']]++;
        }

        return [
            'matched' => $matched->count(), 'by_type' => array_merge(array_fill_keys(array_keys(ContactDirectory::TYPES), 0), $matched->countBy('type')->all()),
            'eligible' => $eligible, 'suppressed' => $suppressed, 'counts' => $counts, 'at' => $at->toIso8601String(),
        ];
    }

    /** Resumen de ambos canales para el constructor de segmentos (conteo en vivo). */
    public function preview(string $match, array $rules, int $sample = 10): array
    {
        $email = $this->resolve('email', $match, $rules);
        $wa = $this->resolve('whatsapp', $match, $rules);
        $summary = fn (array $a) => ['eligible' => $a['eligible']->count()] + $a['counts'];

        return [
            'matched' => $email['matched'], 'by_type' => $email['by_type'], 'email' => $summary($email), 'whatsapp' => $summary($wa),
            'sample' => $email['eligible']->concat($email['suppressed'])->sortBy('name')->take($sample)->map(fn (array $c) => [
                'type' => $c['type'], 'type_label' => $c['type_label'], 'id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'email' => $c['email'],
                'phone' => $c['phone'], 'country' => $c['country'], 'destination' => $c['destination'],
                'consent' => ['email' => $c['consent']['email'] ?? null, 'whatsapp' => $c['consent']['whatsapp'] ?? null],
            ])->values()->all(),
        ];
    }

    /** MKT-BR-14: contactos que recibieron otra campaña en las 72 h previas. @return array<string, true> */
    private function recentlyContacted(Carbon $at, ?string $excludeCampaignId): array
    {
        return CampaignRecipient::whereNotNull('sent_at')->whereIn('status', ['enviado', 'entregado'])
            ->whereBetween('sent_at', [$at->copy()->utc()->subHours(self::FATIGUE_HOURS), $at->copy()->utc()])
            ->when($excludeCampaignId, fn ($q) => $q->where('campaign_id', '<>', $excludeCampaignId))
            ->get(['subject_type', 'subject_id'])->mapWithKeys(fn ($r) => ["{$r->subject_type}:{$r->subject_id}" => true])->all();
    }
}
