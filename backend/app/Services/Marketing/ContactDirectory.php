<?php

namespace App\Services\Marketing;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Iam\User;
use App\Models\Marketing\Consent;
use App\Models\Marketing\ConsentEvent;
use App\Models\Marketing\Subscriber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contacto unificado (MKT-BR-15, read model `mkt_contacts_v`): clientes + leads abiertos no duplicados por email con clientes +
 * suscriptores no vinculados, deduplicados por email. Cada contacto lleva los datos que usan las reglas de segmento (MKT-BR-13),
 * las variables de plantilla y su consentimiento por canal.
 */
class ContactDirectory
{
    public const TYPES = ['client' => 'Cliente', 'lead' => 'Lead', 'subscriber' => 'Suscriptor'];

    public const OPEN_LEADS = ['nuevo', 'contactado', 'calificado'];

    /** Estados de reserva que cuentan como compra (ni borrador ni cancelada). */
    public const PURCHASE_EXCLUDED = [Reservation::DRAFT, Reservation::CANCELLED];

    /** @var Collection<string, array>|null */
    private ?Collection $cache = null;

    /** Solicitud dueña de la caché: el servicio puede sobrevivir entre solicitudes (controladores cacheados por la ruta, workers). */
    private ?int $owner = null;

    /** @return Collection<string, array> clave `tipo:id` */
    public function all(): Collection
    {
        $owner = app()->bound('request') ? spl_object_id(app('request')) : null;
        if ($owner !== $this->owner || app()->runningInConsole() && ! app()->runningUnitTests()) {
            $this->cache = null;
            $this->owner = $owner;
        }

        return $this->cache ??= $this->build();
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    public function find(string $type, string $id): ?array
    {
        return $this->all()->get("{$type}:{$id}") ?? $this->build(true)->get("{$type}:{$id}");
    }

    /** Busca por email o móvil normalizado (webhooks, leads nuevos). */
    public function findByAddress(string $channel, string $address): Collection
    {
        $needle = $channel === 'email' ? mb_strtolower(trim($address)) : self::mobile($address);

        return $needle === null ? collect() : $this->build(true)->filter(fn (array $c) => ($channel === 'email' ? $c['email'] : $c['mobile']) === $needle)->values();
    }

    /**
     * Normaliza un móvil (MKT-BR-06): Perú → 9 dígitos que inician en 9 (+51…); internacional → E.164 de 8 a 15 dígitos.
     * Los fijos (p. ej. 01 4455667) devuelven null.
     */
    public static function mobile(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+') && ! str_starts_with($digits, '51')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? "+{$digits}" : null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '51')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) === 9 && $digits[0] === '9' ? "+51{$digits}" : null;
    }

    /**
     * Estado del consentimiento por canal: `otorgado`, `revocado` o null (sin registro). Con `$at` se reconstruye a esa fecha
     * desde el historial inmutable (evaluación histórica de audiencias).
     *
     * @return array<string, array<string, ?string>> clave `tipo:id` → canal → estado
     */
    public function consentStates(?Carbon $at = null): array
    {
        $states = [];
        if ($at === null) {
            foreach (Consent::get(['subject_type', 'subject_id', 'channel', 'status']) as $c) {
                $states["{$c->subject_type}:{$c->subject_id}"][$c->channel] = $c->status;
            }

            return $states;
        }
        $events = ConsentEvent::query()->join('mkt_consents as c', 'c.id', '=', 'mkt_consent_events.consent_id')
            ->where('mkt_consent_events.occurred_at', '<=', $at->copy()->utc())->orderBy('mkt_consent_events.occurred_at')
            ->get(['c.subject_type', 'c.subject_id', 'c.channel', 'mkt_consent_events.event']);
        foreach ($events as $e) {
            $states["{$e->subject_type}:{$e->subject_id}"][$e->channel] = $e->event;
        }

        return $states;
    }

    private function build(bool $withLinked = false): Collection
    {
        $owners = User::pluck('name', 'id');
        $purchases = Reservation::whereNotIn('status', self::PURCHASE_EXCLUDED)
            ->select('client_id', DB::raw('max(created_at) as last_at'), DB::raw("sum(total_amount * CASE WHEN currency = 'PEN' THEN 1 ELSE fx_rate END) as spend"),
                DB::raw('min(travel_from) FILTER (WHERE travel_from >= current_date) as next_trip'))
            ->groupBy('client_id')->get()->keyBy('client_id');
        $destinations = DB::table('crm_opportunities')->whereNull('deleted_at')->whereNotNull('destination_label')
            ->orderBy('created_at')->pluck('destination_label', 'client_id');
        $leadChannels = Lead::withTrashed()->pluck('channel_code', 'id');
        $leadDestinations = Lead::withTrashed()->pluck('destination_label', 'id');

        $contacts = collect();
        $emails = [];
        foreach (Client::orderBy('code')->get() as $c) {
            $p = $purchases->get($c->id);
            $email = $c->email ? mb_strtolower(trim($c->email)) : null;
            $contacts->put("client:{$c->id}", $this->row('client', $c->id, $c->code, $c->name, $email, $c->phone, [
                'country' => $c->country, 'segment' => $c->segment_code, 'tags' => array_values((array) $c->tags), 'nps' => $c->nps_last,
                'destination' => $destinations[$c->id] ?? ($c->source_lead_id ? ($leadDestinations[$c->source_lead_id] ?? null) : null),
                'source' => $c->source_lead_id ? ($leadChannels[$c->source_lead_id] ?? 'directo') : 'directo',
                'last_purchase_at' => $p?->last_at ? Carbon::parse($p->last_at)->toIso8601String() : null, 'spend_pen' => round((float) ($p?->spend ?? 0), 2),
                'next_trip' => $p?->next_trip, 'birth_date' => $c->birth_date?->toDateString(), 'owner' => $owners[$c->owner_id] ?? null,
                'kind' => $c->kind,
            ]));
            if ($email) {
                $emails[$email] = true;
            }
        }
        foreach (Lead::whereIn('status', $withLinked ? [...self::OPEN_LEADS, 'convertido', 'descartado'] : self::OPEN_LEADS)->orderBy('code')->get() as $l) {
            $email = $l->email ? mb_strtolower(trim($l->email)) : null;
            if (! $withLinked && $email && isset($emails[$email])) {
                continue; // MKT-BR-15: el lead duplicado de un cliente no es un contacto aparte.
            }
            $contacts->put("lead:{$l->id}", $this->row('lead', $l->id, $l->code, $l->full_name, $email, $l->phone, [
                'destination' => $l->destination_label, 'source' => $l->channel_code, 'owner' => $owners[$l->owner_id] ?? null, 'next_trip' => $l->travel_date?->toDateString(),
                'lead_status' => $l->status,
            ]));
            if ($email) {
                $emails[$email] = true;
            }
        }
        $subscribers = Subscriber::orderBy('code');
        if (! $withLinked) {
            $subscribers->whereNull('client_id')->whereNull('lead_id');
        }
        foreach ($subscribers->get() as $s) {
            $email = mb_strtolower(trim($s->email));
            if (! $withLinked && isset($emails[$email])) {
                continue;
            }
            $contacts->put("subscriber:{$s->id}", $this->row('subscriber', $s->id, $s->code, $s->full_name, $email, $s->phone, [
                'country' => $s->country, 'destination' => $s->interest_destination, 'source' => 'newsletter', 'source_label' => $s->source,
                'birth_date' => $s->birth_date?->toDateString(), 'subscribed_at' => $s->created_at?->toIso8601String(),
            ]));
            $emails[$email] = true;
        }

        return $contacts;
    }

    private function row(string $type, string $id, string $code, string $name, ?string $email, ?string $phone, array $extra): array
    {
        return array_merge([
            'type' => $type, 'type_label' => self::TYPES[$type], 'id' => $id, 'code' => $code, 'name' => $name, 'email' => $email ?: null,
            'phone' => $phone, 'mobile' => self::mobile($phone), 'country' => null, 'segment' => null, 'tags' => [], 'nps' => null, 'destination' => null,
            'source' => null, 'last_purchase_at' => null, 'spend_pen' => 0.0, 'next_trip' => null, 'birth_date' => null, 'owner' => null,
        ], $extra);
    }
}
