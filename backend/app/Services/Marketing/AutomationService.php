<?php

namespace App\Services\Marketing;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Channel\EcomOrder;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\Marketing\Automation;
use App\Models\Marketing\AutomationEnrollment;
use App\Models\Marketing\AutomationRun;
use App\Models\Marketing\Subscriber;
use App\Models\Marketing\Template;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automatizaciones (spec 14 §3.11, §8 RunAutomations): cola calculada con datos reales, supresión por finalidad (MKT-BR-16: las
 * comerciales exigen consentimiento, las de servicio solo respetan la baja) e idempotencia por disparador (MKT-BR-17).
 */
class AutomationService
{
    public const SERVICE_KEYS = ['pretrip', 'survey'];

    public function __construct(
        private readonly ContactDirectory $contacts,
        private readonly MessageRenderer $renderer,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
    ) {}

    /**
     * Cola actual: disparadores aún no procesados, cada uno con su motivo de supresión (o null si se enviará).
     *
     * @return Collection<int, array{subject_type: string, subject_id: string, name: string, ref_type: string, ref_id: string, ref_code: string, address: ?string, reason: ?string, contact: array}>
     */
    public function queue(Automation $automation): Collection
    {
        $done = AutomationEnrollment::where('automation_id', $automation->id)->get(['subject_type', 'subject_id', 'ref_type', 'ref_id'])
            ->mapWithKeys(fn ($e) => ["{$e->subject_type}:{$e->subject_id}:{$e->ref_type}:{$e->ref_id}" => true]);
        $states = $this->contacts->consentStates();

        return $this->triggers($automation->key)->reject(fn ($t) => isset($done["{$t['subject_type']}:{$t['subject_id']}:{$t['ref_type']}:{$t['ref_id']}"]))
            ->map(function (array $t) use ($automation, $states) {
                $contact = $this->contacts->find($t['subject_type'], $t['subject_id']);
                $address = $contact ? ($automation->channel === 'email' ? $contact['email'] : $contact['mobile']) : null;
                $state = $states["{$t['subject_type']}:{$t['subject_id']}"][$automation->channel] ?? null;
                $reason = match (true) {
                    $state === 'revocado' => 'optout',
                    $address === null => 'nodata',
                    $automation->purpose === 'comercial' && $state !== 'otorgado' => 'consent',
                    default => null,
                };

                return $t + ['name' => $contact['name'] ?? $t['name'], 'address' => $address, 'reason' => $reason, 'contact' => $contact];
            })->values();
    }

    /** «Ejecutar ahora» o JOB-17: procesa la cola una sola vez por disparador. */
    public function run(Automation $automation, ?User $actor): AutomationRun
    {
        $a = $automation->fresh(['template']);
        if ($a->status !== 'activa') {
            throw ApiException::rule('MKT-AUT-01', "La automatización {$a->code} está pausada: actívela para ejecutarla.");
        }
        $this->assertTemplate($a->template);
        $run = AutomationRun::create(['automation_id' => $a->id, 'started_at' => now(), 'triggered_by' => $actor?->id]);
        $sent = $suppressed = 0;
        $queue = $this->queue($a);
        foreach ($queue as $item) {
            try {
                DB::transaction(function () use ($a, $run, $item) {
                    AutomationEnrollment::create(['automation_id' => $a->id, 'run_id' => $run->id, 'subject_type' => $item['subject_type'], 'subject_id' => $item['subject_id'],
                        'name' => $item['name'], 'ref_type' => $item['ref_type'], 'ref_id' => $item['ref_id'], 'ref_code' => $item['ref_code'],
                        'status' => $item['reason'] ? 'suprimido' : 'enviado', 'reason' => $item['reason'], 'processed_at' => now()]);
                });
            } catch (UniqueConstraintViolationException) {
                continue; // MKT-BR-17: otra ejecución ya procesó este disparador.
            }
            if ($item['reason']) {
                $suppressed++;

                continue;
            }
            $tpl = $a->template;
            $this->notify->push("marketing.auto.{$a->key}", $a->channel, $item['address'], $this->renderer->render($tpl->subject, $item['contact'], $a->channel) ?: $a->name,
                $this->renderer->render($tpl->body, $item['contact'], $a->channel), ['related_type' => 'mkt_automation', 'related_id' => $a->id,
                    'client_id' => $item['subject_type'] === 'client' ? $item['subject_id'] : null]);
            $sent++;
        }
        $run->forceFill(['finished_at' => now(), 'queued' => $queue->count(), 'sent' => $sent, 'suppressed' => $suppressed])->save();
        Automation::whereKey($a->id)->update(['sent_total' => DB::raw("sent_total + {$sent}"), 'suppressed_total' => DB::raw("suppressed_total + {$suppressed}"),
            'last_run_at' => now(), 'updated_at' => now()]);
        $this->audit->log('Automatización', $a->code, 'ejecutar', null, "{$sent} enviado(s) · {$suppressed} suprimido(s)", actor: $actor, system: $actor === null);

        return $run;
    }

    public function activate(Automation $automation, User $actor): Automation
    {
        $this->assertTemplate($automation->template);

        return $this->setStatus($automation, 'activa', 'activar', $actor);
    }

    public function pause(Automation $automation, User $actor): Automation
    {
        return $this->setStatus($automation, 'pausada', 'pausar', $actor);
    }

    /** PUT: plantilla del mismo canal y descripción de la espera. */
    public function configure(Automation $automation, array $data, User $actor): Automation
    {
        $ref = $data['template_id'] ?? null;
        $template = $ref ? Template::where(fn ($q) => Str::isUuid((string) $ref) ? $q->whereKey($ref) : $q->where('code', $ref))->first() : $automation->template;
        if (! $template || $template->channel !== $automation->channel) {
            throw ApiException::invalid('template_id', 'Elija una plantilla del canal de la automatización.');
        }
        if ($automation->status === 'activa') {
            $this->assertTemplate($template);
        }
        $delay = trim((string) ($data['delay_desc'] ?? $automation->delay_desc));
        if ($delay === '' || mb_strlen($delay) > 80) {
            throw ApiException::invalid('delay_desc', 'Indique la espera (máx. 80 caracteres).');
        }
        $before = "{$automation->template->code} · {$automation->delay_desc}";
        $automation->forceFill(['template_id' => $template->id, 'delay_desc' => $delay])->save();
        $this->audit->log('Automatización', $automation->code, 'configurar', $before, "{$template->code} · {$delay}", actor: $actor);

        return $automation->fresh('template');
    }

    /** JOB-17: ejecuta las automatizaciones activas de todos sus disparadores. @return array<string, int> código => enviados */
    public function runActive(): array
    {
        $out = [];
        foreach (Automation::where('status', 'activa')->orderBy('code')->get() as $a) {
            try {
                $out[$a->code] = $this->run($a, null)->sent;
            } catch (ApiException) {
                $out[$a->code] = 0;
            }
        }

        return $out;
    }

    private function assertTemplate(Template $template): void
    {
        if (! $template->usable()) {
            throw ApiException::unprocessable('MKT-BR-03', "La plantilla de WhatsApp {$template->name} no está aprobada por Meta.");
        }
    }

    private function setStatus(Automation $automation, string $status, string $action, User $actor): Automation
    {
        if ($automation->status === $status) {
            return $automation;
        }
        $before = Automation::STATUS_LABELS[$automation->status];
        $automation->forceFill(['status' => $status])->save();
        $this->audit->log('Automatización', $automation->code, $action, $before, Automation::STATUS_LABELS[$status], actor: $actor);

        return $automation;
    }

    /** Disparadores por tipo (§8): datos reales del tenant. @return Collection<int, array> */
    private function triggers(string $key): Collection
    {
        $now = now();
        $today = $this->clock->today();
        $client = fn (Client $c, string $refType, string $refId, string $refCode) => ['subject_type' => 'client', 'subject_id' => $c->id, 'name' => $c->name,
            'ref_type' => $refType, 'ref_id' => $refId, 'ref_code' => $refCode];

        return (match ($key) {
            'welcome' => Subscriber::where('created_at', '>=', $now->copy()->subDays(7))->orderBy('created_at')->get()
                ->map(fn (Subscriber $s) => ['subject_type' => 'subscriber', 'subject_id' => $s->id, 'name' => $s->full_name, 'ref_type' => 'subscriber', 'ref_id' => $s->id, 'ref_code' => $s->code]),
            'cart' => EcomOrder::with('client')->whereIn('status', ['pago_pendiente'])->where('ordered_at', '<', $now->copy()->subHour())->orderBy('ordered_at')->get()
                ->filter(fn (EcomOrder $o) => $o->client)->map(fn (EcomOrder $o) => $client($o->client, 'ecom_order', $o->id, $o->code)),
            'pretrip' => Reservation::with('client')->whereIn('status', [Reservation::CONFIRMED, Reservation::PENDING_PAYMENT, Reservation::PENDING_PROVIDER])
                ->whereBetween('travel_from', [$today->toDateString(), $today->copy()->addDays(3)->toDateString()])->orderBy('travel_from')->get()
                ->filter(fn (Reservation $r) => $r->client)->map(fn (Reservation $r) => $client($r->client, 'reservation', $r->id, $r->code)),
            'survey' => Reservation::with('client')->where('status', Reservation::COMPLETED)
                ->whereBetween('travel_to', [$today->copy()->subDays(7)->toDateString(), $today->toDateString()])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('crm_surveys')->whereColumn('crm_surveys.reservation_id', 'booking_reservations.id'))
                ->orderBy('travel_to')->get()->filter(fn (Reservation $r) => $r->client)->map(fn (Reservation $r) => $client($r->client, 'reservation', $r->id, $r->code)),
            'birthday' => Client::whereNotNull('birth_date')->get()->filter(function (Client $c) use ($today) {
                $next = Carbon::create($today->year, $c->birth_date->month, min($c->birth_date->day, 28), 0, 0, 0, $today->timezone);
                if ($c->birth_date->day > 28) {
                    $next->day(min($c->birth_date->day, $next->daysInMonth));
                }
                if ($next->lessThan($today)) {
                    $next->addYear();
                }

                return $next->diffInDays($today, true) <= 7;
            })->map(fn (Client $c) => $client($c, 'client', $c->id, $c->code)),
            'reactivation' => $this->contacts->all()->filter(fn (array $c) => $c['type'] === 'client' && $c['last_purchase_at'] !== null
                && Carbon::parse($c['last_purchase_at'])->lessThan($now->copy()->subDays(365)))
                ->map(fn (array $c) => ['subject_type' => 'client', 'subject_id' => $c['id'], 'name' => $c['name'], 'ref_type' => 'client', 'ref_id' => $c['id'], 'ref_code' => $c['code']]),
            default => collect(),
        })->values();
    }
}
