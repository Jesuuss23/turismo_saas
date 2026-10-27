<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Http\Resources\Quotes\QuotePresenter;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Channel\SupportTicket;
use App\Models\Crm\Client;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Ops\Voucher;
use App\Models\Quotes\Quote;
use App\Models\Sys\NotifMessage;
use App\Services\AuditLogger;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fin;
use App\Services\Finance\FxRates;
use App\Services\Finance\PaymentService;
use App\Services\Notify;
use App\Services\Quotes\QuoteWorkflow;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Portal del cliente (spec 08 §6.6, PROC-051): su cuenta (PCL-BR-01), cotizaciones no borrador, viajes, documentos, pagos en línea y solicitudes.
 * Los internos previsualizan en solo lectura (PCL-BR-10): Ventas solo su cartera.
 */
class ClientPortal
{
    public const REJECT_REASONS = ['Precio', 'Cambio de fechas', 'Eligió otra agencia', 'Cambio de destino', 'Viaje cancelado', 'Otro'];

    public const PAY_METHOD = 'Tarjeta (portal cliente)';

    /** Permisos que habilitan la previsualización interna (§6.6). */
    public const PREVIEW_PERMISSIONS = ['channels.b2b.manage', 'channels.cms.publish', 'crm.clients.manage'];

    public function __construct(
        private readonly QuotePresenter $quotes,
        private readonly QuoteWorkflow $workflow,
        private readonly SupportTickets $tickets,
        private readonly PaymentService $payments,
        private readonly SandboxGateway $gateway,
        private readonly CrmAccess $crm,
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** PCL-BR-01: el cliente ve su cuenta (cualquier otro id se ignora); sin vínculo → 403. */
    public function clientOf(User $user): Client
    {
        if (! $user->hasRole('customer') || ! $user->client_id || ! ($client = Client::find($user->client_id))) {
            throw ApiException::forbidden('PCL-BR-01', 'Su usuario no está vinculado a una cuenta de cliente.');
        }

        return $client;
    }

    /** PCL-BR-10: Ventas solo clientes de su cartera; además el cliente debe tener cotizaciones no borrador o reservas. */
    public function previewable(User $user): Builder
    {
        return $this->crm->clients(Client::query(), $user)->where(fn ($q) => $q
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('quote_quotes as q')->whereColumn('q.client_id', 'crm_clients.id')->where('q.status', '<>', Quote::DRAFT)->whereNull('q.deleted_at'))
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('booking_reservations as r')->whereColumn('r.client_id', 'crm_clients.id')->where('r.status', '<>', Reservation::DRAFT)->whereNull('r.deleted_at')));
    }

    public function previewClient(User $user, string $clientId): Client
    {
        $client = Client::where('id', preg_match('/^[0-9a-f-]{36}$/i', $clientId) ? $clientId : null)->orWhere('code', $clientId)->first() ?? throw ApiException::notFound();
        if (! $this->previewable($user)->whereKey($client->id)->exists()) {
            throw ApiException::forbidden('PCL-BR-10', 'Registro fuera de su cartera');
        }

        return $client;
    }

    public function home(Client $client): array
    {
        $today = $this->clock->today();
        $quotes = $this->quoteQuery($client)->with('currentVersion')->get();
        $pending = $quotes->filter(fn (Quote $q) => in_array($q->status, Quote::FOLLOW_UP, true) && ! $q->isExpired($today));
        $trips = $this->reservationQuery($client)->where('status', '<>', Reservation::CANCELLED)->orderBy('travel_from')->get();
        $next = $trips->first(fn (Reservation $r) => $r->travel_to->gte($today));
        $open = $this->receivableQuery($client)->whereIn('status', Receivable::OPEN)->orderBy('due_date')->get();
        $owner = $client->owner;

        return [
            'client' => ['id' => $client->id, 'code' => $client->code, 'name' => $client->name, 'email' => $client->email],
            'kpis' => [
                'quotes_pending' => $pending->count(), 'upcoming_trips' => $trips->filter(fn ($r) => $r->travel_from->gte($today))->count(),
                'balance' => $this->sumPen($open->map(fn (Receivable $r) => [$r->balance(), $r->currency])), 'overdue' => $open->filter(fn ($r) => $r->due_date->lt($today))->count(),
            ],
            'pending' => [
                ...$pending->map(fn (Quote $q) => ['type' => 'quote', 'code' => $q->code, 'label' => "Responder la propuesta {$q->code}", 'detail' => $q->title,
                    'due' => $q->valid_until->toDateString()])->values()->all(),
                ...$open->map(fn (Receivable $r) => ['type' => 'receivable', 'code' => $r->code, 'id' => $r->id, 'label' => "Pagar {$r->concept}",
                    'detail' => "{$r->currency} {$r->balance()}", 'due' => $r->due_date->toDateString(), 'overdue' => $r->due_date->lt($today)])->values()->all(),
            ],
            'next_trip' => $next ? $this->reservationRow($next) + ['days_to_go' => max(0, (int) $today->diffInDays($next->travel_from, false))] : null,
            'advisor' => $owner ? ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone ?? null] : null,
            'notifications' => $this->notifications($client)->take(5)->values()->all(),
        ];
    }

    /** PCL-BR-02: cotizaciones ≠ borrador. */
    public function quotes(Client $client): array
    {
        return $this->quoteQuery($client)->with('currentVersion')->orderByDesc('created_at')->get()->map(fn (Quote $q) => [
            'code' => $q->code, 'title' => $q->title, ...$this->quotes->status($q), 'currency' => $q->currency, 'version_no' => $q->current_version_no,
            'total' => (string) $q->currentVersion?->grand_total, 'valid_until' => $q->valid_until->toDateString(), 'travel_from' => $q->travel_from->toDateString(),
            'travel_to' => $q->travel_to->toDateString(),
        ])->all();
    }

    public function quote(Client $client, string $code): Quote
    {
        return $this->quoteQuery($client)->where('code', $code)->first() ?? throw ApiException::notFound();
    }

    /** PCL-BR-06: abrir una cotización `enviada` la marca `vista` solo si el actor es el cliente. */
    public function view(Quote $quote, User $actor, ?string $ip = null, ?string $agent = null): Quote
    {
        if ($actor->isExternal() && $quote->status === Quote::SENT && ! $quote->isExpired($this->clock->today())) {
            try {
                return $this->workflow->registerView($quote, 'portal', null, $ip, $agent);
            } catch (Throwable) {
                // Sin enlace generado no hay vista que registrar (QTE-BR-22); el cliente igual ve su propuesta.
            }
        }

        return $quote;
    }

    public function presentQuote(Quote $quote): array
    {
        return $this->quotes->portal($quote) + ['reject_reasons' => self::REJECT_REASONS, 'lock_version' => $quote->lock_version];
    }

    /** PCL-BR-03/04: no vencida; nombre de quien aprueba y aceptación de condiciones; evidencia completa. */
    public function approve(Quote $quote, array $data, User $actor, ?string $ip): Quote
    {
        if ($quote->isExpired($this->clock->today())) {
            throw ApiException::unprocessable('PCL-BR-03', 'Cotización vencida: no se puede aprobar. Solicite a su asesor una versión actualizada.');
        }
        $name = trim((string) ($data['approver_name'] ?? ''));
        if ($name === '') {
            throw ApiException::invalid('approver_name', 'Indique el nombre de quien aprueba.');
        }
        if (! filter_var($data['accept_terms'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ApiException::invalid('accept_terms', 'Debe aceptar las condiciones para aprobar.');
        }
        $note = trim((string) ($data['note'] ?? ''));

        return $this->workflow->approve($quote, ['approved_by_name' => mb_substr($name, 0, 150), 'channel' => 'Portal cliente',
            'ref' => "Usuario {$actor->email}".($note !== '' ? ' · '.mb_substr($note, 0, 300) : ''), 'ip' => $ip], null, portal: true);
    }

    /** PCL-BR-05: motivo de la lista y comentario obligatorios. */
    public function reject(Quote $quote, array $data): Quote
    {
        $reason = (string) ($data['reason'] ?? '');
        if (! in_array($reason, self::REJECT_REASONS, true)) {
            throw ApiException::invalid('reason', 'Seleccione el motivo del rechazo.');
        }
        $comment = trim((string) ($data['comment'] ?? ''));
        if ($comment === '') {
            throw ApiException::invalid('comment', 'Cuéntenos el detalle del rechazo.');
        }

        return $this->workflow->reject($quote, "{$reason}: ".mb_substr($comment, 0, 1500), null, portal: true);
    }

    /** Cotización vencida → "Solicitar actualización" crea una solicitud de categoría Cotizaciones. */
    public function requestUpdate(Client $client, Quote $quote, array $data, User $actor): SupportTicket
    {
        $message = trim((string) ($data['message'] ?? '')) ?: "Solicito una versión actualizada de la propuesta {$quote->code} ({$quote->title}).";

        return $this->tickets->forClient($client, ['subject' => "Actualizar cotización {$quote->code}", 'category' => 'Cotizaciones', 'message' => $message], $actor);
    }

    /** PCL-BR-02: reservas ≠ borrador. */
    public function reservations(Client $client): array
    {
        return $this->reservationQuery($client)->orderByDesc('travel_from')->get()->map(fn (Reservation $r) => $this->reservationRow($r))->all();
    }

    public function reservation(Client $client, string $code): array
    {
        $r = $this->reservationQuery($client)->where('code', $code)->first() ?? throw ApiException::notFound();
        $vouchers = Voucher::where('reservation_id', $r->id)->where('status', '<>', Voucher::VOIDED)->orderBy('service_date')->get();

        return $this->reservationRow($r) + [
            'services' => BookedService::where('reservation_id', $r->id)->orderBy('service_date')->orderBy('service_time')->get()->map(fn (BookedService $s) => [
                'code' => $s->code, 'name' => $s->name, 'date' => $s->service_date->toDateString(), 'time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null,
                'status' => $s->status, 'status_label' => BookedService::STATUS_LABELS[$s->status] ?? $s->status, 'pax' => $s->pax,
            ])->all(),
            'passengers' => Passenger::where('reservation_id', $r->id)->orderBy('seq')->get()->map(fn (Passenger $p) => [
                'name' => $p->full_name, 'doc_type' => $p->doc_type, 'doc' => $p->doc_number ? '••••'.substr((string) $p->doc_number, -4) : null, 'type' => $p->pax_type, 'lead' => (bool) $p->is_lead,
            ])->all(),
            'receivables' => $this->receivableQuery($client)->where('reservation_id', $r->id)->orderBy('due_date')->get()->map(fn ($x) => $this->receivableRow($x))->all(),
            'vouchers' => $vouchers->map(fn (Voucher $v) => ['code' => $v->code, 'service' => $v->service_label, 'date' => $v->service_date->toDateString(),
                'status_label' => Voucher::STATUS_LABELS[$v->status], 'version' => $v->version])->all(),
            'itinerary_available' => in_array($r->status, [Reservation::CONFIRMED, Reservation::IN_OPERATION, Reservation::COMPLETED], true),
            'policies' => array_values($r->policies_snapshot ?? []),
        ];
    }

    /** PCL-BR-08: vouchers no anulados, comprobantes, propuestas aprobadas/convertidas e itinerarios de reservas confirmadas/en operación/completadas. */
    public function documents(Client $client): array
    {
        $reservations = $this->reservationQuery($client)->get()->keyBy('id');
        $docs = collect();
        Voucher::whereIn('reservation_id', $reservations->keys())->where('status', '<>', Voucher::VOIDED)->orderByDesc('issued_at')->get()
            ->each(fn (Voucher $v) => $docs->push(['kind' => 'Voucher', 'code' => $v->code, 'label' => "Voucher {$v->code} · {$v->service_label}",
                'reservation' => $reservations[$v->reservation_id]->code, 'date' => $v->service_date->toDateString()]));
        Invoice::where('client_id', $client->id)->whereIn('status', Invoice::COUNTED)->orderByDesc('issue_date')->get()
            ->each(fn (Invoice $i) => $docs->push(['kind' => Invoice::TYPE_LABELS[$i->doc_type] ?? 'Comprobante', 'code' => $i->number,
                'label' => (Invoice::TYPE_LABELS[$i->doc_type] ?? 'Comprobante')." {$i->number} · {$i->currency} {$i->total_amount}", 'reservation' => $reservations[$i->reservation_id]->code ?? null,
                'date' => $i->issue_date?->toDateString()]));
        Quote::where('client_id', $client->id)->whereIn('status', [Quote::APPROVED, Quote::CONVERTED])->orderByDesc('approved_at')->get()
            ->each(fn (Quote $q) => $docs->push(['kind' => 'Propuesta', 'code' => $q->code, 'label' => "Propuesta {$q->code} v{$q->current_version_no} · {$q->title}",
                'reservation' => null, 'date' => $q->approved_at?->toDateString()]));
        $reservations->filter(fn (Reservation $r) => in_array($r->status, [Reservation::CONFIRMED, Reservation::IN_OPERATION, Reservation::COMPLETED], true))
            ->each(fn (Reservation $r) => $docs->push(['kind' => 'Itinerario', 'code' => $r->code, 'label' => "Itinerario {$r->code} · {$r->title}",
                'reservation' => $r->code, 'date' => $r->travel_from->toDateString()]));

        return $docs->values()->all();
    }

    /** PCL-BR-02: CxC ≠ cancelada. */
    public function receivables(Client $client): array
    {
        return $this->receivableQuery($client)->with('reservation:id,code,title')->orderBy('due_date')->get()->map(fn ($r) => $this->receivableRow($r))->all();
    }

    public function paymentHistory(Client $client): array
    {
        return Payment::where('client_id', $client->id)->where('kind', 'cobro')->with('reservation:id,code')->orderByDesc('created_at')->limit(200)->get()
            ->map(fn (Payment $p) => ['code' => $p->code, 'date' => $p->paid_on?->toDateString(), 'method' => $p->method, 'amount' => (string) $p->amount, 'currency' => $p->currency,
                'status' => $p->status, 'status_label' => $p->status === 'pendiente' ? 'Por validar' : Payment::STATUS_LABELS[$p->status], 'reservation' => $p->reservation?->code,
                'reference' => $p->status === 'rechazado' ? $p->reject_reason : null])->all();
    }

    /** RT-9 / PCL-BR-07: monto > 0 y ≤ saldo; rechazado registra pago `rechazado` y mantiene la obligación. */
    public function pay(Client $client, string $receivableId, array $data, User $actor): array
    {
        $receivable = $this->receivableQuery($client)->whereKey(preg_match('/^[0-9a-f-]{36}$/i', $receivableId) ? $receivableId : null)->first() ?? throw ApiException::notFound();
        if (! in_array($receivable->status, Receivable::OPEN, true)) {
            throw ApiException::rule('RT-9', "La obligación {$receivable->code} no tiene saldo pendiente.");
        }
        $amount = Fin::r2($data['amount'] ?? 0);
        if ($amount <= 0 || $amount > (float) $receivable->balance() + Fin::EPS) {
            throw ApiException::unprocessable('RT-9', "Regla 9: pago asociado a obligación. El monto debe ser mayor a 0 y no exceder el saldo ({$receivable->currency} {$receivable->balance()}).");
        }
        $charge = $this->gateway->charge((array) ($data['card'] ?? $data));
        $masked = "•••• {$charge['last4']}";
        if ($charge['result'] === 'rechazado') {
            $payment = DB::transaction(function () use ($receivable, $amount, $charge, $masked, $actor) {
                $payment = Payment::create(['code' => $this->sequences->next('payment'), 'receivable_id' => $receivable->id, 'reservation_id' => $receivable->reservation_id,
                    'client_id' => $receivable->client_id, 'kind' => 'cobro', 'source' => 'portal_cliente', 'method' => self::PAY_METHOD, 'amount' => Fin::money($amount),
                    'currency' => $receivable->currency, 'fx_rate' => $receivable->currency === 'PEN' ? null : $this->fx->rate($receivable->currency),
                    'paid_on' => $this->clock->today()->toDateString(), 'reference' => "{$charge['txn_id']} · {$masked}", 'status' => 'rechazado',
                    'reject_reason' => $charge['message'], 'registered_by' => $actor->id]);
                Fin::history('payment', $payment->id, 'rechazado', "Rechazado por la pasarela: {$charge['message']}", $actor);
                $this->audit->log('Pago', $payment->code, 'pago_rechazado', null, "{$receivable->code} · {$masked} · {$charge['message']}", actor: $actor);

                return $payment;
            });

            return ['result' => 'rechazado', 'message' => "{$charge['message']}. Su obligación se mantiene; puede reintentar con otra tarjeta.", 'payment' => $payment->code,
                'receivable' => $this->receivableRow($receivable->fresh())];
        }
        $payment = $this->payments->register($receivable, ['method' => self::PAY_METHOD, 'amount' => Fin::money($amount), 'reference' => "{$charge['txn_id']} · {$masked}",
            'note' => "Pago en línea desde el portal del cliente · {$charge['message']}"], $actor, 'portal_cliente');
        $data = ['client_id' => $client->id, 'related_type' => 'payment', 'related_id' => $payment->id];
        if ($client->email) {
            $this->notify->push('pago.recibido', 'email', $client->email, "Pago recibido — {$receivable->code}", "{$payment->currency} {$payment->amount} · {$masked}", $data);
        }
        if ($client->owner) {
            $this->notify->push('pago.recibido', 'portal', $client->owner, "Pago en línea de {$client->name}", "{$receivable->code} · {$payment->currency} {$payment->amount}", $data);
        }

        return ['result' => 'aprobado', 'message' => "Pago aprobado · {$charge['message']}", 'payment' => $payment->code, 'receivable' => $this->receivableRow($receivable->fresh())];
    }

    /** Notificaciones del cliente: mensajes vinculados a su cuenta en canales email/portal. */
    public function notifications(Client $client): Collection
    {
        $users = User::where('client_id', $client->id)->pluck('id');

        return NotifMessage::where(fn ($q) => $q->where('client_id', $client->id)->where('channel', 'email')->orWhereIn('user_id', $users))
            ->orderByDesc('created_at')->limit(50)->get()
            ->map(fn (NotifMessage $n) => ['id' => $n->id, 'event' => $n->event, 'subject' => $n->subject, 'body' => $n->body, 'channel' => $n->channel,
                'at' => $n->created_at->toIso8601String(), 'read' => (bool) $n->read_at]);
    }

    public function ticketList(Client $client): array
    {
        return SupportTicket::where('client_id', $client->id)->where('source', 'portal_cliente')->with(['reservation:id,code', 'owner:id,name'])->orderByDesc('created_at')->get()
            ->map(fn (SupportTicket $t) => SupportTickets::row($t))->all();
    }

    public function createTicket(Client $client, array $data, User $actor): SupportTicket
    {
        $reservation = ! empty($data['reservation']) ? $this->reservationQuery($client)->where('code', $data['reservation'])->first() : null;

        return $this->tickets->forClient($client, $data, $actor, $reservation);
    }

    private function quoteQuery(Client $client): Builder
    {
        return Quote::where('client_id', $client->id)->where('status', '<>', Quote::DRAFT);
    }

    private function reservationQuery(Client $client): Builder
    {
        return Reservation::where('client_id', $client->id)->where('status', '<>', Reservation::DRAFT);
    }

    private function receivableQuery(Client $client): Builder
    {
        return Receivable::where('client_id', $client->id)->where('status', '<>', 'cancelada');
    }

    private function reservationRow(Reservation $r): array
    {
        return ['code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status, 'channel' => $r->channel,
            'travel_from' => $r->travel_from->toDateString(), 'travel_to' => $r->travel_to->toDateString(), 'currency' => $r->currency, 'total' => (string) $r->total_amount,
            'pax' => Passenger::where('reservation_id', $r->id)->count()];
    }

    private function receivableRow(Receivable $r): array
    {
        $today = $this->clock->today();

        return ['id' => $r->id, 'code' => $r->code, 'concept' => $r->concept, 'reservation' => $r->reservation?->code, 'currency' => $r->currency,
            'amount' => (string) $r->amount, 'paid' => (string) $r->paid_amount, 'balance' => $r->balance(), 'due_date' => $r->due_date->toDateString(),
            'status' => $r->status, 'status_label' => Receivable::STATUS_LABELS[$r->status], 'overdue' => in_array($r->status, Receivable::OPEN, true) && $r->due_date->lt($today),
            'can_pay' => in_array($r->status, Receivable::OPEN, true) && (float) $r->balance() > 0];
    }

    /** @param  Collection<int, array{0: string, 1: string}>  $amounts */
    private function sumPen(Collection $amounts): string
    {
        return Fin::money($amounts->sum(fn ($a) => $this->fx->toPen($a[0], $a[1])));
    }
}
