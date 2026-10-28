<?php

namespace App\Services\Experience;

use App\Domain\Bookings\RuleResolver;
use App\Exceptions\ApiException;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Experience\PaxMessage;
use App\Models\Experience\PaxMessageTemplate;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Sys\NotifMessage;
use App\Notifications\Channels\PermanentDeliveryFailure;
use App\Services\AuditLogger;
use App\Services\Integrations\WebhookService;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mensajes al pasajero (spec 17 §2 `admin/comunicaciones`, §3.4–3.5, MSG-01..03): plantillas con variables y condiciones,
 * programación por reserva viva (SchedulePaxMessages), despacho (DispatchDuePaxMessages, 5 intentos con backoff),
 * envíos manuales y de prueba, y acuses de los proveedores (entregado/leído/fallido).
 */
class PaxMessageService
{
    public const MAX_ATTEMPTS = 5;

    /** Estados vivos: no borrador ni cancelada (MSG-02). */
    private const LIVE = [Reservation::CONFIRMED, Reservation::PENDING_PAYMENT, Reservation::PENDING_PROVIDER, Reservation::RESCHEDULED, 'parcial', 'incidencia',
        Reservation::IN_OPERATION, Reservation::COMPLETED, Reservation::PENDING_AVAILABILITY];

    public function __construct(
        private readonly RuleResolver $rules,
        private readonly AuditLogger $audit,
        private readonly SequenceService $sequences,
        private readonly TenantContext $tenant,
    ) {}

    // --- plantillas (MSG-01) ---

    public function templates(): array
    {
        $history = PaxMessage::whereNotNull('template_id')->selectRaw('template_id, count(*) AS n')->groupBy('template_id')->pluck('n', 'template_id');

        return PaxMessageTemplate::orderBy('code')->get()->map(fn (PaxMessageTemplate $t) => $this->presentTemplate($t, (int) ($history[$t->id] ?? 0)))->all();
    }

    public function presentTemplate(PaxMessageTemplate $t, ?int $history = null): array
    {
        $history ??= PaxMessage::where('template_id', $t->id)->count();
        $requires = $t->conditions['requiresRule'] ?? null;

        return [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'event' => $t->event, 'event_label' => ExperienceCatalog::MESSAGE_EVENTS[$t->event] ?? $t->event,
            'offset_hours' => $t->offset_hours, 'offset_from_rule' => $t->offset_from_rule, 'effective_offset_hours' => $this->offset($t), 'channels' => $t->channels,
            'subject' => $t->subject, 'body' => $t->body, 'lang' => $t->lang, 'group_code' => $t->group_code, 'conditions' => (object) ($t->conditions ?? []),
            'whatsapp_template_name' => $t->whatsapp_template_name, 'active' => $t->active, 'lock_version' => $t->lock_version, 'history' => $history,
            'deletable' => $history === 0, 'rule_ok' => ! $requires || $this->rules->on($requires), 'when' => $this->whenLabel($t),
        ];
    }

    public function meta(): array
    {
        return [
            'events' => ExperienceCatalog::MESSAGE_EVENTS, 'channels' => ExperienceCatalog::MESSAGE_CHANNELS, 'variables' => ExperienceCatalog::VARIABLES,
            'rules' => ['R-DEPOSIT', 'R-COMPANIONS', 'R-PAX-FEEDBACK', 'R-REVIEW-INVITE'], 'sales_channels' => Reservation::CHANNELS,
            'messages_on' => $this->rules->on('R-PAX-MESSAGES'), 'review_invite_on' => $this->rules->on('R-REVIEW-INVITE'),
            'review_days_after' => (int) $this->rules->param('R-REVIEW-INVITE', 'daysAfter', null, 2),
        ];
    }

    public function saveTemplate(array $data, User $actor, ?PaxMessageTemplate $t = null): array
    {
        $row = $this->validTemplate($data, $t);
        $before = $t ? $this->templateLabel($t) : null;
        $wasActive = $t?->active;
        $t = DB::transaction(function () use ($t, $row, $actor, $before, $wasActive) {
            if ($t) {
                $t->fill($row)->save();
                $action = array_keys($row) === ['active'] ? ($t->active ? 'activar' : 'desactivar') : 'editar';
                if ($wasActive !== null && $wasActive !== $t->active && $action === 'editar') {
                    $this->audit->log('Plantilla de mensaje', $t->code, $t->active ? 'activar' : 'desactivar', $before, $this->templateLabel($t), actor: $actor);
                }
                $this->audit->log('Plantilla de mensaje', $t->code, $action, $before, $this->templateLabel($t), actor: $actor);
            } else {
                $t = PaxMessageTemplate::create($row + ['code' => $this->sequences->next('pax_template')]);
                $t->group_code ??= $t->code;
                $t->save();
                $this->audit->log('Plantilla de mensaje', $t->code, 'crear', null, $this->templateLabel($t), actor: $actor);
            }

            return $t;
        });
        $this->schedule();

        return $this->presentTemplate($t->fresh());
    }

    /** Duplicar en otro idioma: misma agrupación (`group_code`), inactiva hasta revisarla. */
    public function duplicate(PaxMessageTemplate $t, string $lang, User $actor): array
    {
        $lang = strtoupper($lang);
        if (! in_array($lang, ['ES', 'EN'], true)) {
            throw ApiException::invalid('lang', 'Idioma inválido (ES o EN).');
        }
        $group = $t->group_code ?? $t->code;
        if (PaxMessageTemplate::where('group_code', $group)->where('lang', $lang)->exists()) {
            throw ApiException::unprocessable('MSG-01', "MSG-01: la plantilla ya tiene una variante en {$lang}.");
        }
        $copy = DB::transaction(function () use ($t, $lang, $group, $actor) {
            $copy = PaxMessageTemplate::create(Arr::only($t->toArray(), ['name', 'event', 'offset_hours', 'offset_from_rule', 'channels', 'subject', 'body', 'conditions', 'whatsapp_template_name'])
                + ['code' => $this->sequences->next('pax_template'), 'lang' => $lang, 'group_code' => $group, 'active' => false]);
            $copy->name = mb_substr($t->name." ({$lang})", 0, 120);
            $copy->save();
            $this->audit->log('Plantilla de mensaje', $copy->code, 'duplicar', $t->code, $this->templateLabel($copy), actor: $actor);

            return $copy;
        });

        return $this->presentTemplate($copy);
    }

    public function deleteTemplate(PaxMessageTemplate $t, User $actor): void
    {
        if (PaxMessage::where('template_id', $t->id)->exists()) {
            throw ApiException::unprocessable('MSG-04', 'La plantilla tiene historial de envíos: desactívela en lugar de eliminarla.');
        }
        DB::transaction(function () use ($t, $actor) {
            $this->audit->log('Plantilla de mensaje', $t->code, 'eliminar', $this->templateLabel($t), null, actor: $actor);
            $t->delete();
        });
    }

    /** MSG-01: ≥1 canal; asunto si hay canal ≠ WhatsApp; variables de la lista; WhatsApp ≤ 1 024 caracteres. */
    private function validTemplate(array $data, ?PaxMessageTemplate $t): array
    {
        $get = fn (string $k, $default = null) => array_key_exists($k, $data) ? $data[$k] : ($t ? $t->{$k} : $default);
        $row = [];
        if (! $t || array_key_exists('name', $data)) {
            $name = trim((string) $get('name', ''));
            if ($name === '' || mb_strlen($name) > 120) {
                throw ApiException::invalid('name', 'Indique el nombre (hasta 120 caracteres).');
            }
            $row['name'] = $name;
        }
        $event = (string) $get('event', '');
        if (! isset(ExperienceCatalog::MESSAGE_EVENTS[$event])) {
            throw ApiException::invalid('event', 'Evento inválido.');
        }
        $offset = $get('offset_hours', 0);
        if (! is_numeric($offset) || (int) $offset != $offset || $offset < -720 || $offset > 720) {
            throw ApiException::invalid('offset_hours', 'El desfase va de −720 a 720 horas.');
        }
        $channels = array_values(array_unique(array_intersect(array_keys(ExperienceCatalog::MESSAGE_CHANNELS), (array) $get('channels', []))));
        if ($channels === []) {
            throw ApiException::unprocessable('MSG-01', 'MSG-01: elija al menos un canal.');
        }
        $subject = trim((string) $get('subject', ''));
        if ($subject === '' && array_diff($channels, ['whatsapp']) !== []) {
            throw ApiException::unprocessable('MSG-01', 'MSG-01: el asunto es obligatorio si hay un canal distinto de WhatsApp.');
        }
        $body = trim((string) $get('body', ''));
        if ($body === '') {
            throw ApiException::invalid('body', 'Escriba el mensaje.');
        }
        if (in_array('whatsapp', $channels, true) && mb_strlen($body) > 1024) {
            throw ApiException::unprocessable('MSG-01', 'MSG-01: el mensaje de WhatsApp tiene un máximo de 1 024 caracteres.');
        }
        $unknown = array_diff(self::variables($subject.' '.$body), array_keys(ExperienceCatalog::VARIABLES));
        if ($unknown !== []) {
            throw ApiException::unprocessable('MSG-01', 'MSG-01: variable desconocida {{'.reset($unknown).'}}.');
        }
        $lang = strtoupper((string) $get('lang', 'ES'));
        if (! in_array($lang, ['ES', 'EN'], true)) {
            throw ApiException::invalid('lang', 'Idioma inválido (ES o EN).');
        }
        $conditions = (array) $get('conditions', []);
        $clean = [];
        if (! empty($conditions['requiresRule'])) {
            $this->rules->rule((string) $conditions['requiresRule']) ?? throw ApiException::invalid('conditions', 'Regla inválida.');
            $clean['requiresRule'] = (string) $conditions['requiresRule'];
        }
        if (! empty($conditions['products'])) {
            $clean['products'] = array_values(array_filter((array) $conditions['products'], fn ($id) => Str::isUuid((string) $id)));
        }
        if (! empty($conditions['channels'])) {
            $clean['channels'] = array_values(array_intersect(Reservation::CHANNELS, (array) $conditions['channels']));
        }
        foreach (['onlyIfBalance', 'onlyIfMissingDocs'] as $flag) {
            if (filter_var($conditions[$flag] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $clean[$flag] = true;
            }
        }

        return $row + [
            'event' => $event, 'offset_hours' => (int) $offset, 'offset_from_rule' => filter_var($get('offset_from_rule', false), FILTER_VALIDATE_BOOLEAN),
            'channels' => $channels, 'subject' => $subject !== '' ? mb_substr($subject, 0, 200) : null, 'body' => $body, 'lang' => $lang,
            'conditions' => (object) $clean, 'whatsapp_template_name' => trim((string) $get('whatsapp_template_name', '')) ?: null,
            'active' => filter_var($get('active', true), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** @return list<string> */
    public static function variables(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    // --- vista previa y prueba ---

    public function preview(PaxMessageTemplate $t, ?string $reservationId, string $channel): array
    {
        $reservation = $reservationId ? Reservation::with('client')->whereKey($reservationId)->first() : $this->sampleReservation();
        $vars = $reservation ? $this->vars($reservation) : $this->sampleVars();
        $body = self::render($t->body, $vars);

        return ['channel' => $channel, 'subject' => $channel === 'whatsapp' ? null : self::render((string) $t->subject, $vars), 'body' => $body, 'length' => mb_strlen($body),
            'reservation' => $reservation ? ['id' => $reservation->id, 'code' => $reservation->code, 'title' => $reservation->title] : null,
            'recipient' => $reservation ? $this->recipient($reservation, $channel) : null];
    }

    public function test(PaxMessageTemplate $t, User $actor, ?string $channel = null): array
    {
        $channel = in_array($channel, $t->channels, true) ? $channel : $t->channels[0];
        $preview = $this->preview($t, null, $channel);
        $recipient = match ($channel) { 'email' => $actor->email, 'whatsapp' => $actor->phone ?? $actor->email, default => "usuario:{$actor->email}" };
        $msg = PaxMessage::create([
            'code' => $this->sequences->next('pax_message'), 'reservation_id' => null, 'template_id' => $t->id, 'channel' => $channel, 'recipient' => $recipient,
            'subject' => '[Prueba] '.($preview['subject'] ?? $t->name), 'body' => $preview['body'], 'status' => 'programado', 'origin' => 'prueba', 'scheduled_at' => now(), 'lang' => $t->lang,
        ]);
        $this->audit->log('Plantilla de mensaje', $t->code, 'envio_prueba', null, "{$channel} → {$recipient}", actor: $actor);
        $this->deliver($msg);

        return $this->present($msg->fresh());
    }

    // --- programación (MSG-02) ---

    /**
     * SchedulePaxMessages: calcula (reserva × plantilla × canal) y hace upsert de los `programado` futuros; retira los que ya no
     * corresponden; con R-PAX-MESSAGES apagada retira todos los automáticos pendientes. Idempotente (índice único parcial).
     *
     * @param  string|null  $reservationId  solo esta reserva; `$confirmedNow` = la confirmación ocurre ahora (base del evento).
     */
    public function schedule(?string $reservationId = null, bool $confirmedNow = false): array
    {
        $pending = PaxMessage::where('origin', 'auto')->where('status', 'programado')->when($reservationId, fn ($q) => $q->where('reservation_id', $reservationId));
        if (! $this->rules->on('R-PAX-MESSAGES')) {
            $n = $pending->update(['status' => 'cancelado', 'error' => 'R-PAX-MESSAGES desactivada', 'updated_at' => now()]);

            return ['scheduled' => 0, 'updated' => 0, 'cancelled' => $n, 'messages_on' => false];
        }
        $templates = PaxMessageTemplate::where('active', true)->whereNotIn('event', ['cambio_horario'])->orderBy('code')->get();
        $reservations = Reservation::with('client')->whereIn('status', self::LIVE)
            ->when($reservationId, fn ($q) => $q->whereKey($reservationId), fn ($q) => $q->where('travel_to', '>=', now()->subDays(45)->toDateString()))->get();
        $stats = ['scheduled' => 0, 'updated' => 0, 'cancelled' => 0, 'messages_on' => true];
        $existing = PaxMessage::where('origin', 'auto')->where('status', '<>', 'cancelado')->whereIn('reservation_id', $reservations->pluck('id'))->get()
            ->groupBy('reservation_id');
        foreach ($reservations as $r) {
            $want = [];
            foreach ($this->templatesFor($r, $templates) as $t) {
                $at = $this->eventTime($r, $t, $confirmedNow);
                if (! $at || ! $this->conditionsMet($r, $t)) {
                    continue;
                }
                foreach ($t->channels as $channel) {
                    $want["{$t->id}|{$channel}"] = [$t, $channel, $at];
                }
            }
            $rows = ($existing[$r->id] ?? collect())->keyBy(fn (PaxMessage $m) => "{$m->template_id}|{$m->channel}");
            foreach ($want as $k => [$t, $channel, $at]) {
                $row = $rows[$k] ?? null;
                if ($row) {
                    if ($row->status === 'programado' && ! $row->scheduled_at?->equalTo($at) && $at->isFuture()) {
                        $row->forceFill(['scheduled_at' => $at] + $this->rendered($r, $t, $channel))->save();
                        $stats['updated']++;
                    }

                    continue;
                }
                // No se reenvían eventos pasados al recalcular.
                if ($at->isPast() && ! ($confirmedNow && $t->event === 'reserva_confirmada')) {
                    continue;
                }
                $recipient = $this->recipient($r, $channel);
                if (! $recipient) {
                    continue;
                }
                PaxMessage::create(['code' => $this->sequences->next('pax_message'), 'reservation_id' => $r->id, 'template_id' => $t->id, 'channel' => $channel,
                    'recipient' => $recipient, 'status' => 'programado', 'origin' => 'auto', 'scheduled_at' => $at->isPast() ? now() : $at, 'lang' => $t->lang]
                    + $this->rendered($r, $t, $channel));
                $stats['scheduled']++;
            }
            foreach ($rows as $k => $row) {
                if (! isset($want[$k]) && $row->status === 'programado') {
                    $row->forceFill(['status' => 'cancelado', 'error' => 'Ya no corresponde (plantilla, regla o condición)'])->save();
                    $stats['cancelled']++;
                }
            }
        }

        return $stats;
    }

    /** PMT-007: cambio de horario de una reserva (ServiceScheduleChanged / reprogramación). */
    public function scheduleChange(Reservation $reservation): int
    {
        if (! $this->rules->on('R-PAX-MESSAGES') || ! in_array($reservation->status, self::LIVE, true)) {
            return 0;
        }
        $reservation->loadMissing('client');
        $n = 0;
        $templates = PaxMessageTemplate::where('active', true)->where('event', 'cambio_horario')->get();
        foreach ($this->templatesFor($reservation, $templates) as $t) {
            if (! $this->conditionsMet($reservation, $t)) {
                continue;
            }
            foreach ($t->channels as $channel) {
                $recipient = $this->recipient($reservation, $channel);
                if ($recipient) {
                    // Un cambio nuevo reemplaza el aviso anterior (índice único por reserva × plantilla × canal).
                    PaxMessage::where('reservation_id', $reservation->id)->where('template_id', $t->id)->where('channel', $channel)->where('origin', 'auto')
                        ->where('status', '<>', 'cancelado')->update(['status' => 'cancelado', 'error' => 'Reemplazado por un cambio posterior', 'updated_at' => now()]);
                    PaxMessage::create(['code' => $this->sequences->next('pax_message'), 'reservation_id' => $reservation->id, 'template_id' => $t->id, 'channel' => $channel,
                        'recipient' => $recipient, 'status' => 'programado', 'origin' => 'auto', 'scheduled_at' => now(), 'lang' => $t->lang] + $this->rendered($reservation, $t, $channel));
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Variante de idioma por grupo: EN si el cliente no es de Perú (si existe), si no ES. */
    private function templatesFor(Reservation $r, Collection $templates): Collection
    {
        $lang = self::langFor($r);

        return $templates->groupBy(fn (PaxMessageTemplate $t) => $t->group_code ?? $t->code)
            ->map(fn (Collection $g) => $g->firstWhere('lang', $lang) ?? $g->firstWhere('lang', 'ES') ?? $g->first())->values();
    }

    public static function langFor(Reservation $r): string
    {
        $country = mb_strtolower(trim((string) $r->client?->country));

        return $country === '' || in_array($country, ['perú', 'peru', 'pe'], true) ? 'ES' : 'EN';
    }

    /** Hora del evento en la zona del tenant + desfase; null si el evento no aplica a la reserva. */
    public function eventTime(Reservation $r, PaxMessageTemplate $t, bool $confirmedNow = false): ?CarbonImmutable
    {
        $tz = $this->tenant->tenant()?->timezone() ?? 'America/Lima';
        $at = fn (string $date, int $hour) => CarbonImmutable::parse($date, $tz)->setTime($hour, 0)->utc();
        $base = match ($t->event) {
            'reserva_confirmada' => in_array($r->status, [Reservation::PENDING_AVAILABILITY], true) ? null
                : ($confirmedNow ? CarbonImmutable::now() : CarbonImmutable::parse($r->created_at)),
            'saldo_vence' => ($due = $this->balanceDue($r)) ? $at($due, ExperienceCatalog::EVENT_HOURS['saldo_vence']) : null,
            'viaje' => $at($r->travel_from->toDateString(), ExperienceCatalog::EVENT_HOURS['viaje']),
            'fin_viaje' => $at($r->travel_to->toDateString(), ExperienceCatalog::EVENT_HOURS['fin_viaje']),
            'cumpleanos' => ($bd = $this->birthdayOnTrip($r)) ? $at($bd, ExperienceCatalog::EVENT_HOURS['cumpleanos']) : null,
            default => null,
        };

        return $base?->addHours($this->offset($t));
    }

    public function offset(PaxMessageTemplate $t): int
    {
        return $t->offset_from_rule ? (int) $this->rules->param('R-REVIEW-INVITE', 'daysAfter', null, 2) * 24 : $t->offset_hours;
    }

    private function conditionsMet(Reservation $r, PaxMessageTemplate $t): bool
    {
        $c = (array) $t->conditions;
        if (! empty($c['requiresRule']) && ! $this->rules->on($c['requiresRule'])) {
            return false;
        }
        if (! empty($c['channels']) && ! in_array($r->channel, $c['channels'], true)) {
            return false;
        }
        if (! empty($c['products']) && ! DB::table('booking_services')->where('reservation_id', $r->id)->whereIn('product_id', $c['products'])->exists()) {
            return false;
        }
        if (! empty($c['onlyIfBalance']) && $this->balance($r) <= 0) {
            return false;
        }
        if (! empty($c['onlyIfMissingDocs']) && ! $this->missingDocs($r)) {
            return false;
        }
        // Mensajes antes del viaje: no aplican a reservas completadas.
        if ($r->status === Reservation::COMPLETED && in_array($t->event, ['reserva_confirmada', 'saldo_vence', 'viaje', 'cumpleanos'], true)) {
            return false;
        }

        return true;
    }

    private function balance(Reservation $r): float
    {
        return (float) Receivable::where('reservation_id', $r->id)->whereNotIn('status', ['pagada', 'cancelada'])->sum(DB::raw('amount - paid_amount'));
    }

    private function balanceDue(Reservation $r): ?string
    {
        $due = Receivable::where('reservation_id', $r->id)->whereNotIn('status', ['pagada', 'cancelada'])->orderBy('due_date')->value('due_date');

        return $due ? CarbonImmutable::parse($due)->toDateString() : null;
    }

    private function missingDocs(Reservation $r): bool
    {
        return Passenger::where('reservation_id', $r->id)->where(fn ($q) => $q->where('is_placeholder', true)->orWhereNull('doc_number')->orWhere('doc_number', ''))->exists();
    }

    private function birthdayOnTrip(Reservation $r): ?string
    {
        $birth = $r->client?->birth_date;
        if (! $birth) {
            return null;
        }
        $from = CarbonImmutable::parse($r->travel_from);
        $to = CarbonImmutable::parse($r->travel_to);
        foreach ([$from->year, $to->year] as $year) {
            $day = CarbonImmutable::create($year, $birth->month, min($birth->day, 28 + (int) ($birth->month !== 2) * 3));
            if ($day->betweenIncluded($from, $to)) {
                return $day->toDateString();
            }
        }

        return null;
    }

    // --- render ---

    public function vars(Reservation $r): array
    {
        $r->loadMissing('client');
        $tenant = $this->tenant->tenant();
        $balance = $this->balance($r);
        $due = $this->balanceDue($r);
        $fmt = fn ($d) => $d ? CarbonImmutable::parse($d)->format('d/m/Y') : '—';
        $product = DB::table('booking_services as s')->join('catalog_products as p', 'p.id', '=', 's.product_id')->where('s.reservation_id', $r->id)->orderBy('s.service_date')->value('p.name')
            ?? Str::before($r->title, ' — ');
        $url = rtrim((string) config('turismo.frontend_url'), '/');

        return [
            'pasajero' => $r->client?->name ?? 'Pasajero', 'reserva' => $r->code, 'producto' => $product, 'fecha_viaje' => $fmt($r->travel_from), 'fecha_fin' => $fmt($r->travel_to),
            'saldo' => $r->currency.' '.number_format($balance, 2, '.', ','), 'vencimiento_saldo' => $fmt($due),
            'punto_recojo' => data_get($r->booking, 'pickup.label') ?? data_get($r->booking, 'pickup.point') ?? 'el punto acordado',
            'agencia' => $tenant?->trade_name ?? $tenant?->legal_name ?? 'la agencia', 'enlace_portal' => "{$url}/portal/reservas/{$r->code}",
            'enlace_resena' => "{$url}/sitio/resenas?reserva={$r->code}",
        ];
    }

    public static function render(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? $m[0]), $text);
    }

    private function rendered(Reservation $r, PaxMessageTemplate $t, string $channel): array
    {
        $vars = $this->vars($r);

        return ['subject' => $channel === 'whatsapp' ? null : self::render((string) $t->subject, $vars), 'body' => self::render($t->body, $vars)];
    }

    private function sampleReservation(): ?Reservation
    {
        return Reservation::with('client')->whereIn('status', self::LIVE)->orderBy('travel_from')->where('travel_from', '>=', now()->toDateString())->first()
            ?? Reservation::with('client')->whereIn('status', self::LIVE)->latest('travel_from')->first();
    }

    private function sampleVars(): array
    {
        return array_combine(array_keys(ExperienceCatalog::VARIABLES), array_map(fn ($v) => "[{$v}]", ExperienceCatalog::VARIABLES));
    }

    public function recipient(Reservation $r, string $channel): ?string
    {
        $r->loadMissing('client');

        return match ($channel) {
            'email' => $r->client?->email ?: null,
            'whatsapp' => $r->client?->phone ?: null,
            'portal' => "portal:{$r->code}",
            'push' => "push:{$r->client_id}",
            default => null,
        };
    }

    // --- despacho y acciones ---

    /** DispatchDuePaxMessages: envía los `programado` vencidos (5 intentos con backoff exponencial). */
    public function dispatchDue(int $limit = 200): array
    {
        $sent = $failed = 0;
        foreach (PaxMessage::where('status', 'programado')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit($limit)->get() as $msg) {
            $this->deliver($msg) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Envía por el adapter del canal (sandbox en demo); devuelve true si quedó enviado. */
    public function deliver(PaxMessage $msg): bool
    {
        if ($msg->reservation_id && $msg->origin === 'auto') {
            $r = Reservation::with('client')->find($msg->reservation_id);
            if ($r && $msg->template_id && ($t = PaxMessageTemplate::find($msg->template_id))) {
                $msg->fill($this->rendered($r, $t, $msg->channel));
            }
        }
        $msg->attempts++;
        try {
            $notif = new NotifMessage(['event' => 'pasajero.mensaje', 'channel' => $msg->channel, 'recipient' => $msg->recipient, 'subject' => $msg->subject ?? '', 'body' => $msg->body]);
            app(Notify::class)->sender($msg->channel)->send($notif);
            $msg->forceFill(['status' => 'enviado', 'sent_at' => now(), 'error' => null, 'provider_message_id' => self::providerPrefix($msg->channel).Str::lower((string) Str::ulid())])->save();

            return true;
        } catch (Throwable $e) {
            $permanent = $e instanceof PermanentDeliveryFailure || $msg->attempts >= self::MAX_ATTEMPTS;
            $msg->forceFill(['error' => Str::limit($e->getMessage(), 500, '')] + ($permanent
                ? ['status' => 'fallido'] : ['scheduled_at' => now()->addMinutes(2 ** $msg->attempts)]))->save();
            if ($permanent) {
                $this->failed($msg);
            }

            return false;
        }
    }

    public function sendNow(PaxMessage $msg, User $actor): array
    {
        if ($msg->status !== 'programado') {
            throw ApiException::unprocessable('MSG-05', 'Solo se envían ahora los mensajes programados.');
        }
        $this->deliver($msg);
        $this->audit->log('Mensaje al pasajero', $msg->code, 'enviar', 'programado', $msg->status, actor: $actor);

        return $this->present($msg->fresh());
    }

    public function cancel(PaxMessage $msg, User $actor): array
    {
        if ($msg->status !== 'programado') {
            throw ApiException::unprocessable('MSG-05', 'Solo se cancelan mensajes programados.');
        }
        $msg->forceFill(['status' => 'cancelado', 'error' => "Cancelado por {$actor->name}"])->save();
        $this->audit->log('Mensaje al pasajero', $msg->code, 'cancelar', 'programado', 'cancelado', actor: $actor);

        return $this->present($msg);
    }

    public function retry(PaxMessage $msg, User $actor): array
    {
        if ($msg->status !== 'fallido') {
            throw ApiException::unprocessable('MSG-05', 'Solo se reintentan mensajes fallidos.');
        }
        $msg->forceFill(['status' => 'programado', 'scheduled_at' => now(), 'attempts' => 0, 'error' => null])->save();
        $this->deliver($msg);
        $this->audit->log('Mensaje al pasajero', $msg->code, 'reintentar', 'fallido', $msg->status, actor: $actor);

        return $this->present($msg->fresh());
    }

    /** MSG-03: envío manual (con plantilla o texto libre) a una reserva. */
    public function manual(Reservation $r, array $data, User $actor): array
    {
        $channel = (string) ($data['channel'] ?? '');
        if (! isset(ExperienceCatalog::MESSAGE_CHANNELS[$channel])) {
            throw ApiException::invalid('channel', 'Canal inválido.');
        }
        $recipient = $this->recipient($r, $channel) ?? throw ApiException::unprocessable('MSG-03', 'El cliente no tiene datos de contacto para ese canal.');
        $template = ! empty($data['template_id']) ? PaxMessageTemplate::whereKey($data['template_id'])->first() ?? throw ApiException::invalid('template_id', 'Plantilla no encontrada.') : null;
        if ($template) {
            ['subject' => $subject, 'body' => $body] = $this->rendered($r, $template, $channel);
        } else {
            $body = trim((string) ($data['body'] ?? ''));
            $subject = trim((string) ($data['subject'] ?? '')) ?: null;
            if ($body === '' || ($channel === 'whatsapp' && mb_strlen($body) > 1024)) {
                throw ApiException::unprocessable('MSG-01', 'MSG-01: escriba el mensaje (WhatsApp hasta 1 024 caracteres).');
            }
            $unknown = array_diff(self::variables($subject.' '.$body), array_keys(ExperienceCatalog::VARIABLES));
            if ($unknown !== []) {
                throw ApiException::unprocessable('MSG-01', 'MSG-01: variable desconocida {{'.reset($unknown).'}}.');
            }
            $vars = $this->vars($r);
            $body = self::render($body, $vars);
            $subject = $subject !== null && $channel !== 'whatsapp' ? self::render($subject, $vars) : ($channel === 'whatsapp' ? null : "Mensaje sobre su reserva {$r->code}");
        }
        $msg = PaxMessage::create(['code' => $this->sequences->next('pax_message'), 'reservation_id' => $r->id, 'template_id' => $template?->id, 'channel' => $channel,
            'recipient' => $recipient, 'subject' => $subject, 'body' => $body, 'status' => 'programado', 'origin' => 'manual', 'scheduled_at' => now(),
            'lang' => $template?->lang ?? self::langFor($r), 'created_by' => $actor->id]);
        $this->deliver($msg);
        $this->audit->log('Mensaje al pasajero', $msg->code, 'enviar', null, "manual · {$channel} → {$recipient} · {$r->code} · ".$msg->fresh()->status, actor: $actor);

        return $this->present($msg->fresh());
    }

    /** Acuse del proveedor (SES, WhatsApp Cloud API, FCM): entregado / leído / fallido. Dedupe por provider_message_id y orden de estados. */
    public function ack(string $providerMessageId, string $status, ?string $error = null, ?string $at = null): ?string
    {
        $msg = PaxMessage::where('provider_message_id', $providerMessageId)->first();
        if (! $msg) {
            return null;
        }
        $status = match ($status) { 'delivered', 'entregado', 'Delivery' => 'entregado', 'read', 'leido', 'opened', 'Open' => 'leido',
            'failed', 'fallido', 'Bounce', 'undelivered' => 'fallido', default => null };
        $order = ['enviado' => 1, 'entregado' => 2, 'leido' => 3];
        if (! $status || ($status !== 'fallido' && ($order[$msg->status] ?? 0) >= $order[$status]) || in_array($msg->status, ['fallido', 'cancelado'], true)) {
            return $msg->status;
        }
        $when = $at ? CarbonImmutable::parse($at) : now();
        $msg->forceFill(match ($status) {
            'entregado' => ['status' => 'entregado', 'delivered_at' => $when],
            'leido' => ['status' => 'leido', 'read_at' => $when, 'delivered_at' => $msg->delivered_at ?? $when],
            default => ['status' => 'fallido', 'error' => $error ?? 'Rechazado por el proveedor'],
        })->save();
        if ($status === 'fallido') {
            $this->failed($msg);
        }

        return $msg->status;
    }

    /** «Actualizar acuses» (demo): en sandbox los proveedores confirman la entrega al cabo de un minuto y la lectura (WhatsApp/push/portal) a los cinco. */
    public function syncAcks(User $actor): array
    {
        $n = 0;
        foreach (PaxMessage::whereIn('status', ['enviado', 'entregado'])->where('sent_at', '<=', now()->subMinute())->get() as $msg) {
            $before = $msg->status;
            $this->ack($msg->provider_message_id, 'delivered', at: $msg->sent_at->addMinute()->toIso8601String());
            if ($msg->channel !== 'email' && $msg->sent_at->lte(now()->subMinutes(5))) {
                $this->ack($msg->provider_message_id, 'read');
            }
            $n += (int) ($msg->fresh()->status !== $before);
        }
        $this->audit->log('Mensaje al pasajero', 'acuses', 'sincronizar_estados', null, "{$n} mensaje(s) actualizados", actor: $actor);

        return ['updated' => $n];
    }

    private function failed(PaxMessage $msg): void
    {
        app(WebhookService::class)->dispatch('passenger.message.failed', $msg->code, ['code' => $msg->code, 'channel' => $msg->channel,
            'reservation' => $msg->reservation_id ? Reservation::whereKey($msg->reservation_id)->value('code') : null, 'error' => $msg->error, 'attempts' => $msg->attempts]);
    }

    private static function providerPrefix(string $channel): string
    {
        return ['email' => 'ses-', 'whatsapp' => 'wamid.', 'push' => 'fcm-', 'portal' => 'portal-'][$channel] ?? 'msg-';
    }

    // --- listados ---

    public function messages(array $filters): array
    {
        $q = PaxMessage::with(['reservation:id,code,title', 'template:id,code,name'])->orderByDesc(DB::raw('coalesce(sent_at, scheduled_at, created_at)'));
        $view = $filters['view'] ?? null;
        if ($view === 'proximos') {
            $q->where('status', 'programado')->reorder('scheduled_at');
        } elseif ($view === 'historial') {
            $q->where('status', '<>', 'programado');
        }
        foreach (['status', 'channel', 'origin'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }
        if (! empty($filters['reservation'])) {
            $q->whereHas('reservation', fn ($r) => $r->where('code', $filters['reservation']));
        }

        return $q->limit(300)->get()->map(fn (PaxMessage $m) => $this->present($m))->all();
    }

    public function present(PaxMessage $m): array
    {
        return [
            'id' => $m->id, 'code' => $m->code, 'channel' => $m->channel, 'recipient' => $m->recipient, 'subject' => $m->subject, 'body' => $m->body, 'status' => $m->status,
            'origin' => $m->origin, 'lang' => $m->lang, 'attempts' => $m->attempts, 'error' => $m->error, 'provider_message_id' => $m->provider_message_id,
            'scheduled_at' => $m->scheduled_at?->toIso8601String(), 'sent_at' => $m->sent_at?->toIso8601String(), 'delivered_at' => $m->delivered_at?->toIso8601String(),
            'read_at' => $m->read_at?->toIso8601String(),
            'reservation' => $m->reservation ? ['id' => $m->reservation->id, 'code' => $m->reservation->code, 'title' => $m->reservation->title] : null,
            'template' => $m->template ? ['id' => $m->template->id, 'code' => $m->template->code, 'name' => $m->template->name] : null,
        ];
    }

    /** Reservas vivas para el envío manual y la vista previa. */
    public function reservations(?string $search = null): array
    {
        return Reservation::with('client:id,name,email,phone,country')->whereIn('status', self::LIVE)
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$search}%")->orWhere('title', 'ilike', "%{$search}%")))
            ->orderBy('travel_from')->limit(50)->get()
            ->map(fn (Reservation $r) => ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'travel_from' => $r->travel_from->toDateString(),
                'client' => $r->client?->name, 'lang' => self::langFor($r), 'has_email' => (bool) $r->client?->email, 'has_phone' => (bool) $r->client?->phone])->all();
    }

    private function templateLabel(PaxMessageTemplate $t): string
    {
        return ($t->active ? 'Activa' : 'Inactiva')." · {$t->event} {$t->offset_hours} h · ".implode(', ', (array) $t->channels)." · {$t->lang}";
    }

    private function whenLabel(PaxMessageTemplate $t): string
    {
        $h = $this->offset($t);
        $event = mb_strtolower(ExperienceCatalog::MESSAGE_EVENTS[$t->event] ?? $t->event);
        if ($h === 0) {
            return "Al {$event}";
        }
        $span = abs($h) % 24 === 0 ? (abs($h) / 24).' día(s)' : abs($h).' h';

        return $h < 0 ? "{$span} antes · {$event}" : "{$span} después · {$event}";
    }
}
