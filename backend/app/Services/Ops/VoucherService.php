<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\ProductInclusion;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Order;
use App\Models\Ops\Voucher;
use App\Models\Ops\VoucherVersion;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Voucher (PROC-035, §4.4): validación OPS-BR-40..47, emisión, versionado con snapshot, reenvío, canje y anulación. */
class VoucherService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** @return array{blocks: list<array{code: string, text: string, status: int}>, warnings: list<array{code: string, text: string}>} */
    public function check(BookedService $service): array
    {
        $blocks = [];
        $warnings = [];
        $reservation = Reservation::find($service->reservation_id);
        if (! in_array($service->status, [BookedService::CONFIRMED, BookedService::COMPLETED, BookedService::IN_PROGRESS], true)) {
            $blocks[] = ['code' => 'RT-11', 'status' => 422, 'text' => 'Solo servicios confirmados por el proveedor emiten voucher (está '.mb_strtolower(BookedService::STATUS_LABELS[$service->status] ?? $service->status).').'];
        }
        if (! $reservation || in_array($reservation->status, [Reservation::CANCELLED, Reservation::DRAFT], true)) {
            $blocks[] = ['code' => 'OPS-BR-47', 'status' => 422, 'text' => 'La reserva está cancelada o en borrador.'];
        }
        if (! $service->provider?->isUsable()) {
            $blocks[] = ['code' => 'OPS-BR-42', 'status' => 422, 'text' => 'El proveedor no está activo (contrato no vigente).'];
        }
        if (Voucher::where('reservation_service_id', $service->id)->where('status', Voucher::ISSUED)->exists()) {
            $blocks[] = ['code' => 'OPS-BR-41', 'status' => 409, 'text' => 'El servicio ya tiene un voucher vigente: use "Nueva versión".'];
        }
        if (! $service->confirmation_code) {
            $warnings[] = ['code' => 'OPS-BR-43', 'text' => 'El servicio no tiene código de confirmación del proveedor.'];
        }
        if ($service->status === BookedService::COMPLETED) {
            $warnings[] = ['code' => 'OPS-BR-43', 'text' => 'El servicio ya se ejecutó: el voucher queda como respaldo.'];
        }

        return ['blocks' => $blocks, 'warnings' => $warnings];
    }

    public function issue(BookedService $service, ?string $note, User $actor, string $origin = 'operaciones'): Voucher
    {
        return DB::transaction(function () use ($service, $note, $actor, $origin) {
            $service = BookedService::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $check = $this->check($service);
            if ($block = $check['blocks'][0] ?? null) {
                throw $block['status'] === 409 ? ApiException::rule($block['code'], $block['text'], $check) : ApiException::unprocessable($block['code'], $block['text'], $check);
            }
            $reservation = Reservation::findOrFail($service->reservation_id);
            $code = $this->sequences->next('voucher');
            try {
                $voucher = Voucher::create([
                    'code' => $code, 'reservation_id' => $reservation->id, 'reservation_service_id' => $service->id, 'provider_id' => $service->provider_id,
                    'service_label' => mb_substr($service->name, 0, 200), 'service_date' => $service->service_date->toDateString(),
                    'pax' => Passenger::where('reservation_id', $reservation->id)->count(), 'status' => Voucher::ISSUED, 'version' => 1,
                    'issued_at' => now(), 'issued_by' => $actor->id, 'note' => $note, 'origin' => $origin,
                    'qr_token' => $this->qr($code, $reservation->code, 1),
                ]);
            } catch (QueryException) {
                throw ApiException::rule('OPS-BR-41', 'El servicio ya tiene un voucher vigente: use "Nueva versión".');
            }
            $this->version($voucher, 'Emisión inicial', $actor);
            $this->audit->log('Voucher', $voucher->code, 'emitir', null, "{$service->code} · {$service->name} · {$voucher->pax} pax", actor: $actor);
            $this->notifyAll($voucher, $reservation, 'voucher.emitido', "Voucher {$voucher->code} emitido", true);

            return $voucher->fresh();
        });
    }

    public function resend(Voucher $voucher, User $actor): Voucher
    {
        $this->assertIssued($voucher);
        $reservation = $voucher->reservation;
        if ($email = $reservation?->client?->email) {
            $this->notify->push('voucher.reenviado', 'email', $email, "Voucher {$voucher->code} (v{$voucher->version})", $voucher->service_label, $this->data($voucher, $reservation));
        }
        $this->audit->log('Voucher', $voucher->code, 'reenviar', null, "v{$voucher->version} al cliente", actor: $actor);

        return $voucher;
    }

    /** OPS-BR-44: motivo obligatorio, servicio aún confirmado/completado; conserva el código. */
    public function newVersion(Voucher $voucher, string $reason, User $actor): Voucher
    {
        $this->assertIssued($voucher);
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la nueva versión (OPS-BR-44).');
        }
        $service = BookedService::findOrFail($voucher->reservation_service_id);
        if (! in_array($service->status, [BookedService::CONFIRMED, BookedService::COMPLETED, BookedService::IN_PROGRESS], true)) {
            throw ApiException::unprocessable('OPS-BR-44', 'El servicio ya no está confirmado: no se puede versionar el voucher.');
        }
        $reservation = $voucher->reservation;
        $version = $voucher->version + 1;
        $voucher->forceFill([
            'version' => $version, 'issued_at' => now(), 'issued_by' => $actor->id, 'service_label' => mb_substr($service->name, 0, 200),
            'service_date' => $service->service_date->toDateString(), 'pax' => Passenger::where('reservation_id', $reservation->id)->count(),
            'qr_token' => $this->qr($voucher->code, $reservation->code, $version),
        ])->save();
        $this->version($voucher, trim($reason), $actor);
        $this->audit->log('Voucher', $voucher->code, 'versionar', 'v'.($version - 1), "v{$version} · ".trim($reason), actor: $actor);
        $this->notifyAll($voucher, $reservation, 'voucher.actualizado', "Voucher {$voucher->code} actualizado (v{$version})", false);

        return $voucher->fresh();
    }

    public function redeem(Voucher $voucher, User $actor, string $via = 'web'): Voucher
    {
        $this->assertIssued($voucher);
        $voucher->forceFill(['status' => Voucher::USED, 'used_at' => now(), 'used_by' => $actor->id, 'used_via' => $via])->save();
        $this->audit->log('Voucher', $voucher->code, 'utilizar', 'Emitido', 'Utilizado · '.$via, actor: $actor);

        return $voucher->fresh();
    }

    /** OPS-BR-46: motivo obligatorio; queda en historial y se avisa al proveedor. */
    public function void(Voucher $voucher, string $reason, User $actor): Voucher
    {
        $this->assertIssued($voucher);
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la anulación (OPS-BR-46).');
        }
        $voucher->forceFill(['status' => Voucher::VOIDED, 'void_reason' => trim($reason), 'void_at' => now(), 'void_by' => $actor->id])->save();
        $this->audit->log('Voucher', $voucher->code, 'anular', 'Emitido', 'Anulado · '.trim($reason), actor: $actor);
        if ($email = $voucher->provider?->email) {
            $this->notify->push('voucher.anulado', 'email', $email, "Voucher {$voucher->code} anulado", trim($reason), $this->data($voucher, $voucher->reservation));
        }

        return $voucher->fresh();
    }

    /** Snapshot del documento (§3.10): servicio, proveedor, pasajeros, confirmación, recursos y punto de encuentro. */
    public function snapshot(Voucher $voucher): array
    {
        $service = BookedService::with('provider', 'product')->find($voucher->reservation_service_id);
        $reservation = $voucher->reservation;
        $assignment = Assignment::where('reservation_service_id', $service?->id)->with(['guide', 'driver', 'vehicle'])->first();
        $departure = $service?->departure_id ? Departure::find($service->departure_id) : null;
        $inclusions = $service?->product_id ? ProductInclusion::where('product_id', $service->product_id)->orderBy('sort_order')->get(['kind', 'text']) : collect();
        $passengers = Passenger::where('reservation_id', $reservation?->id)->orderBy('seq')->get();
        $order = Order::where('reservation_id', $reservation?->id)->first();

        return [
            'service' => $service?->name, 'service_code' => $service?->code, 'date' => $service?->service_date->toDateString(),
            'time' => $service?->service_time ? substr((string) $service->service_time, 0, 5) : null,
            'provider' => $service?->provider?->name, 'provider_phone' => $service?->provider?->phone,
            'confirmation_code' => $service?->confirmation_code, 'meeting_point' => $departure?->meeting_point ?? 'Lobby del hotel del pasajero',
            'reservation' => $reservation?->code, 'client' => $reservation?->client?->name,
            'passengers' => $passengers->map(fn (Passenger $p) => ['name' => $p->full_name, 'type' => $p->pax_type, 'lead' => $p->is_lead,
                'relationship' => $p->relationship, 'diet' => $p->diet_notes])->all(),
            'guide' => $assignment?->guide?->full_name, 'guide_phone' => $assignment?->guide?->phone,
            'transport' => $assignment?->vehicle ? $assignment->vehicle->label().($assignment->driver ? " · {$assignment->driver->full_name}" : '') : $assignment?->driver?->full_name,
            'includes' => $inclusions->where('kind', 'include')->pluck('text')->values()->all(),
            'excludes' => $inclusions->where('kind', 'exclude')->pluck('text')->values()->all(),
            'diets' => $passengers->whereNotNull('diet_notes')->map(fn ($p) => "{$p->full_name}: {$p->diet_notes}")->values()->all(),
            'coordinator' => $order?->coordinator?->name, 'emergency' => app(PassengerApp::class)->emergencyNumber(),
            'note' => $voucher->note,
        ];
    }

    /** QR firmado (HMAC) de `{voucher}|{reserva}|{versión}` (antifraude, §11-12). */
    public function qr(string $code, string $reservation, int $version): string
    {
        $payload = "{$code}|{$reservation}|{$version}";

        return $payload.'|'.substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 32);
    }

    public function verifyQr(string $token): bool
    {
        $parts = explode('|', $token);

        return count($parts) === 4 && hash_equals($this->qr($parts[0], $parts[1], (int) $parts[2]), $token);
    }

    private function version(Voucher $voucher, string $reason, User $actor): void
    {
        VoucherVersion::create([
            'voucher_id' => $voucher->id, 'version' => $voucher->version, 'issued_at' => now(), 'issued_by' => $actor->id,
            'reason' => $reason, 'snapshot' => $this->snapshot($voucher),
        ]);
    }

    private function assertIssued(Voucher $voucher): void
    {
        if ($voucher->status !== Voucher::ISSUED) {
            throw ApiException::rule('OPS-BR-45', 'Solo vouchers vigentes (emitidos): este está '.mb_strtolower(Voucher::STATUS_LABELS[$voucher->status]).'.');
        }
    }

    /** Email al cliente, push a los pasajeros con app y (en emisión) email al proveedor. */
    private function notifyAll(Voucher $voucher, Reservation $reservation, string $event, string $subject, bool $provider): void
    {
        $data = $this->data($voucher, $reservation);
        if ($email = $reservation->client?->email) {
            $this->notify->push($event, 'email', $email, $subject, $voucher->service_label, $data);
        }
        foreach (User::where('reservation_id', $reservation->id)->get() as $passenger) {
            $this->notify->push($event, 'push', $passenger, $subject, "{$voucher->service_label} · {$voucher->service_date->toDateString()}", $data);
        }
        if ($provider && ($providerEmail = $voucher->provider?->email)) {
            $this->notify->push($event, 'email', $providerEmail, $subject, "{$reservation->code} · {$voucher->pax} pax · {$voucher->service_date->toDateString()}", $data);
        }
    }

    private function data(Voucher $voucher, ?Reservation $reservation): array
    {
        return ['client_id' => $reservation?->client_id, 'related_type' => 'ops_voucher', 'related_id' => $voucher->id, 'reservation_id' => $reservation?->id];
    }
}
