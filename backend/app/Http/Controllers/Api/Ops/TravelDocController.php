<?php

namespace App\Http\Controllers\Api\Ops;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin\ScheduledJob;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\Ops\TravelDocContact;
use App\Models\Ops\TravelDocItem;
use App\Models\Ops\TravelDocReminder;
use App\Models\Sys\AuditEvent;
use App\Services\Admin\IntegrationAdmin;
use App\Services\AuditLogger;
use App\Services\Bookings\BookingAccess;
use App\Services\Ops\TravelDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 11 §6: documentos de viaje. Operaciones/Manager/Admin (`operations.travel_docs`) ven y gestionan todo; Ventas
 * (`reservations.create`) solo consulta y solicita documentos de sus reservas (DOC-BR-012, alcance forzado en el servidor).
 */
class TravelDocController extends Controller
{
    public function __construct(
        private readonly TravelDocs $docs,
        private readonly BookingAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /ops/travel-docs: resumen por reserva con KPIs. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $this->rows($user, $request->input('filter.period', 'upcoming'));
        $all = $rows;
        if ($status = $request->input('filter.status')) {
            $rows = $rows->filter(fn ($r) => $status === 'por_validar' ? $r['to_validate'] > 0 : $r['status'] === $status);
        }
        if ($request->input('filter.mapi') === 'blocked') {
            $rows = $rows->filter(fn ($r) => $r['gate']['blocked']);
        }

        return response()->json(['data' => $rows->map(fn ($r) => array_diff_key($r, ['passengers' => 1]))->values()->all(), 'meta' => [
            'kpis' => [
                'upcoming' => $all->count(), 'pax_pending' => $all->sum('pax_pending'), 'to_validate' => $all->sum('to_validate'),
                'observed_expired' => $all->sum(fn ($r) => $r['observed'] + $r['expired']), 'mapi_blocked' => $all->filter(fn ($r) => $r['gate']['blocked'])->count(),
            ],
            'can_manage' => $this->canManage($user), 'scope' => $this->canManage($user) ? 'Todas las reservas próximas' : 'Solo sus reservas',
            'statuses' => TravelDocItem::STATUS_LABELS, 'types' => TravelDocItem::TYPES,
        ]]);
    }

    /** GET /ops/travel-docs/passengers: vista por pasajero. */
    public function passengers(Request $request): JsonResponse
    {
        $rows = $this->rows($request->user(), $request->input('filter.period', 'upcoming'))->flatMap(fn ($r) => collect($r['passengers'])->map(fn ($p) => [
            'reservation' => $r['code'], 'reservation_title' => $r['title'], 'travel_from' => $r['travel_from'], 'days_left' => $r['days_left'],
            'passenger_id' => $p['id'], 'name' => $p['name'], 'pax_type' => $p['pax_type'], 'nationality' => $p['nationality'], 'foreign' => $p['foreign'], 'minor' => $p['minor'],
            'status' => $p['status'], 'status_label' => TravelDocItem::STATUS_LABELS[$p['status']],
            'missing' => collect($p['items'])->where('status', '<>', 'completo')->map(fn ($i) => ['type' => $i['type'], 'label' => $i['label'], 'status' => $i['status']])->values()->all(),
        ]));
        if ($status = $request->input('filter.status')) {
            $rows = $rows->where('status', $status);
        }

        return response()->json(['data' => $rows->values()->all()]);
    }

    /** GET /reservations/{id}/travel-docs: checklist completo + gate MAPI + solicitudes y bitácora. */
    public function show(Request $request, string $reservation): JsonResponse
    {
        $r = $this->find($request, $reservation);
        $r->loadMissing(['client:id,code,name,email,phone', 'owner:id,name']);
        $checklist = $this->docs->checklist($r);
        $user = $request->user();

        return response()->json(['data' => [
            'reservation' => ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status,
                'travel_from' => $r->travel_from?->toDateString(), 'travel_to' => $r->travel_to?->toDateString(), 'owner' => $r->owner?->name,
                'days_left' => $r->travel_from ? (int) $this->docs->today()->diffInDays($r->travel_from->copy()->startOfDay(), false) : null],
            'client' => $r->client ? ['code' => $r->client->code, 'name' => $r->client->name, 'email' => $r->client->email, 'phone' => $r->client->phone] : null,
            'status' => $checklist['status'], 'status_label' => TravelDocItem::STATUS_LABELS[$checklist['status']], 'gate' => $checklist['gate'], 'passengers' => $checklist['passengers'],
            'context' => array_diff_key($checklist['context'], ['mapi' => 1]),
            'kpis' => $this->kpis($checklist['passengers']),
            'reminders' => TravelDocReminder::with('sender:id,name')->where('reservation_id', $r->id)->orderByDesc('sent_at')->get()->map(fn (TravelDocReminder $m) => [
                'at' => $m->sent_at->toIso8601String(), 'channels' => $m->channels, 'recipient' => $m->recipient, 'trigger' => $m->trigger, 'days_before' => $m->days_before,
                'items_count' => $m->items_count, 'sent_by' => $m->sender?->name ?? 'Sistema', 'message' => $m->message])->all(),
            'log' => $this->log($r),
            'can_manage' => $this->canManage($user), 'can_request' => $this->canManage($user) || $r->owner_id === $user->id,
            'relations' => TravelDocContact::RELATIONS, 'channels' => $this->docs->settings()['channels'],
        ]]);
    }

    public function gate(Request $request, string $reservation): JsonResponse
    {
        return response()->json(['data' => $this->docs->gate($this->find($request, $reservation))]);
    }

    public function upload(Request $request, string $reservation, string $passenger, string $type): JsonResponse
    {
        $item = $this->docs->upload($this->item($request, $reservation, $passenger, $type), $request->all(), $request->user());

        return response()->json(['data' => $this->itemRow($item), 'message' => 'Documento cargado: queda por validar'], 201);
    }

    public function validateItem(Request $request, string $reservation, string $passenger, string $type): JsonResponse
    {
        $item = $this->item($request, $reservation, $passenger, $type);
        $item->assertIfMatch($request);
        $item = $this->docs->validate($item, $request->only(['doc_number', 'expiry_date', 'note']), $request->user());

        return response()->json(['data' => $this->itemRow($item), 'message' => TravelDocItem::TYPES[$item->doc_type].' validado']);
    }

    public function observe(Request $request, string $reservation, string $passenger, string $type): JsonResponse
    {
        $item = $this->item($request, $reservation, $passenger, $type);
        $item->assertIfMatch($request);
        $item = $this->docs->observe($item, (string) $request->input('reason'), $request->user());

        return response()->json(['data' => $this->itemRow($item), 'message' => 'Documento observado: se avisó al cliente']);
    }

    /** GET …/{type}/file: descarga auditada (`ver_documento`, Ley 29733). */
    public function file(Request $request, string $reservation, string $passenger, string $type): Response
    {
        $item = $this->item($request, $reservation, $passenger, $type);
        $disk = Storage::disk((string) config('turismo.evidence_disk', 'local'));
        if (! $item->storage_path || ! $disk->exists($item->storage_path)) {
            throw ApiException::notFound();
        }
        $this->audit->log('Documento de viaje', $item->reservation?->code ?? $reservation, 'ver_documento', null,
            "{$item->passenger?->full_name} · ".TravelDocItem::TYPES[$item->doc_type]." · {$item->file_name}", actor: $request->user());

        return response($disk->get($item->storage_path), 200, ['Content-Type' => $item->file_mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes((string) $item->file_name).'"', 'Cache-Control' => 'private, no-store']);
    }

    public function contact(Request $request, string $reservation, string $passenger): JsonResponse
    {
        [$r, $p] = $this->passenger($request, $reservation, $passenger);
        $this->docs->contact($r, $p, $request->all(), $request->user());

        return response()->json(['data' => ['ok' => true], 'message' => "Contacto de emergencia de {$p->full_name} registrado"]);
    }

    public function special(Request $request, string $reservation, string $passenger): JsonResponse
    {
        [$r, $p] = $this->passenger($request, $reservation, $passenger);
        $s = $this->docs->special($r, $p, $request->all(), $request->user());

        return response()->json(['data' => ['requires_attention' => $s->requires_attention, 'operational_note' => $s->operational_note], 'message' => 'Atención especial registrada']);
    }

    /** POST /requests: Operaciones o el asesor dueño (DOC-BR-012). */
    public function request(Request $request, string $reservation): JsonResponse
    {
        $r = $this->find($request, $reservation);
        $user = $request->user();
        if (! $this->canManage($user) && $r->owner_id !== $user->id) {
            throw BookingAccess::outOfScope();
        }
        $m = $this->docs->request($r, $request->only(['channels', 'message']), $user);

        return response()->json(['data' => ['channels' => $m->channels, 'recipient' => $m->recipient, 'items_count' => $m->items_count],
            'message' => "Solicitud enviada a {$m->recipient} ({$m->channels})"], 201);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->docs->settings() + ['types' => TravelDocItem::TYPES], 'meta' => ['can_manage' => $this->canManage($request->user())]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->docs->saveSettings($request->all(), $request->user()), 'message' => 'Reglas de documentos guardadas']);
    }

    /** POST /ops/travel-docs/jobs/reminders: ejecuta JOB-11 ahora. */
    public function runReminders(Request $request, IntegrationAdmin $jobs): JsonResponse
    {
        $row = $jobs->run(ScheduledJob::where('code', 'JOB-11')->firstOrFail(), $request->user());

        return response()->json(['data' => $row, 'message' => $row['message']]);
    }

    private function rows(User $user, string $period): Collection
    {
        $query = $period === 'all' ? Reservation::whereIn('status', TravelDocs::ACTIVE) : $this->docs->upcoming();
        $reservations = $this->access->scope($query, $user)->with(['client:id,name', 'owner:id,name'])->orderBy('travel_from')->limit(200)->get();
        $this->docs->ensure($reservations);
        $last = TravelDocReminder::whereIn('reservation_id', $reservations->pluck('id'))->selectRaw('reservation_id, MAX(sent_at) AS at')->groupBy('reservation_id')->pluck('at', 'reservation_id');

        return $reservations->map(function (Reservation $r) use ($last) {
            $s = $this->docs->summary($r);

            return ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'client' => $r->client?->name, 'owner' => $r->owner?->name, 'owner_id' => $r->owner_id,
                'reservation_status' => $r->status, 'travel_from' => $r->travel_from?->toDateString(), 'travel_to' => $r->travel_to?->toDateString(),
                'days_left' => $r->travel_from ? (int) $this->docs->today()->diffInDays($r->travel_from->copy()->startOfDay(), false) : null,
                'status_label' => TravelDocItem::STATUS_LABELS[$s['status']], 'last_reminder' => isset($last[$r->id]) ? Carbon::parse($last[$r->id])->toIso8601String() : null] + $s;
        });
    }

    private function kpis(array $passengers): array
    {
        $items = collect($passengers)->flatMap(fn ($p) => $p['items']);

        return ['passengers' => count($passengers), 'complete' => collect($passengers)->where('status', 'completo')->count(),
            'to_validate' => $items->where('status', 'recibido')->count(), 'pending' => $items->where('status', 'pendiente')->count(),
            'observed' => $items->where('status', 'observado')->count(), 'expired' => $items->where('status', 'vencido')->count()];
    }

    private function log(Reservation $r): array
    {
        $rows = AuditEvent::where('entity', 'Documento de viaje')->where('entity_id', $r->code)->orderByDesc('occurred_at')->limit(60)->get();
        $names = User::whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn ($e) => ['at' => $e->occurred_at?->toIso8601String(), 'action' => $e->action, 'actor' => $names[$e->actor_id] ?? 'Sistema',
            'before' => $e->before_value, 'after' => $e->after_value])->all();
    }

    private function itemRow(TravelDocItem $i): array
    {
        return ['id' => $i->id, 'type' => $i->doc_type, 'status' => $i->status, 'doc_number' => $i->doc_number, 'expiry_date' => $i->expiry_date?->toDateString(),
            'file_name' => $i->file_name, 'observation' => $i->observation, 'lock_version' => (int) $i->lock_version];
    }

    private function canManage(User $user): bool
    {
        return $user->checkPermissionTo('operations.travel_docs');
    }

    private function find(Request $request, string $key): Reservation
    {
        $r = Reservation::where(fn ($q) => Str::isUuid($key) ? $q->whereKey($key) : $q->where('code', mb_strtoupper($key)))->first() ?? throw ApiException::notFound();
        $this->access->ensureVisible($r, $request->user());

        return $r;
    }

    private function item(Request $request, string $reservation, string $passenger, string $type): TravelDocItem
    {
        $r = $this->find($request, $reservation);

        return $this->docs->item($r, $passenger, $type)->setRelation('reservation', $r);
    }

    /** @return array{0: Reservation, 1: Passenger} */
    private function passenger(Request $request, string $reservation, string $passenger): array
    {
        $r = $this->find($request, $reservation);
        $p = Str::isUuid($passenger) ? Passenger::where('reservation_id', $r->id)->find($passenger) : null;

        return [$r, $p ?? throw ApiException::notFound()];
    }
}
