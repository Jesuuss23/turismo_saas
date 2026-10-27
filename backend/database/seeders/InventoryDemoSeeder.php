<?php

namespace Database\Seeders;

use App\Models\Admin\ScheduledJob;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Models\Inventory\AllotmentConsumption;
use App\Models\Inventory\AllotmentDay;
use App\Models\Ops\TravelDocItem;
use App\Models\Ops\TravelDocReminder;
use App\Services\Crm\CrmClock;
use App\Services\Inventory\AllotmentService;
use App\Services\Inventory\ReleaseAllotments;
use App\Services\Ops\TravelDocs;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo de cupos y documentos de viaje (spec 11) para “andes”. La demo no tiene Tambo del Inka, Ferrovía Sur Andina ni el
 * Ministerio de Cultura: los tres contratos usan Hotel Inti Wasi (hab./noche), PeruRail Expedition (asientos) y la entrada a
 * Machu Picchu circuito 2. El job JOB-10 corre como “anoche” (libera lo vencido y cobra la fecha pasada retenida) y luego se
 * acepta una ampliación sobre fechas ya en release, que queda como alerta de release vencido.
 */
class InventoryDemoSeeder extends Seeder
{
    private const PDF = 'JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyA+PgplbmRvYmoKdHJhaWxlcgo8PCAvUm9vdCAxIDAgUiA+PgolJUVPRgo=';

    public function run(): void
    {
        $rosa = User::where('code', 'U05')->firstOrFail();
        Auth::setUser($rosa);
        try {
            $this->allotments($rosa);
            $this->travelDocs($rosa);
        } finally {
            Auth::forgetUser();
        }
    }

    private function allotments(User $rosa): void
    {
        $service = app(AllotmentService::class);
        $today = app(CrmClock::class)->today();
        $srv = fn (string $ref) => SupplierService::where('ref', $ref)->first();
        $contracts = [];
        foreach ([
            ['SRV-008', 'Hotel Inti Wasi · bloqueo temporada alta', 'ADD-IW-2026-03', -14, 75, 6, 7, 95, 'USD', 100, 'Habitaciones dobles estándar; release 7 días, no-show facturado al 100 %.'],
            ['SRV-001', 'PeruRail Expedition · convenio 2026', 'CONV-PR-2026', -14, 90, 12, 10, 140, 'USD', 50, 'Asientos Ollantaytambo – Aguas Calientes ida y vuelta.'],
            ['SRV-003', 'Entradas Machu Picchu circuito 2', 'CUOTA-MC-2026', -7, 60, 20, 3, 152, 'PEN', 0, 'Cupo no nominado se pierde sin penalidad (supuesto 4 de §12).'],
        ] as [$ref, $name, $contractRef, $from, $to, $qty, $release, $rate, $currency, $penalty, $notes]) {
            $s = $srv($ref);
            if (! $s) {
                continue;
            }
            $contracts[$ref] = $service->create(['provider_id' => $s->provider_id, 'service_id' => $s->id, 'name' => $name, 'contract_ref' => $contractRef,
                'valid_from' => $today->copy()->addDays($from)->toDateString(), 'valid_to' => $today->copy()->addDays($to)->toDateString(), 'base_qty' => $qty,
                'release_days' => $release, 'net_rate' => $rate, 'currency' => $currency, 'penalty_pct' => $penalty, 'notes' => $notes], $rosa);
        }
        if ($contracts === []) {
            return;
        }

        // Fechas pasadas ya procesadas por el job (liberado lo no usado), salvo una del hotel retenida para un grupo que no llegó.
        $hotel = $contracts['SRV-008'] ?? null;
        $retainedPast = $today->copy()->subDays(2)->toDateString();
        foreach ($contracts as $a) {
            AllotmentDay::where('allotment_id', $a->id)->whereDate('service_date', '<', $today->toDateString())
                ->when($a->is($hotel), fn ($q) => $q->whereDate('service_date', '<>', $retainedPast))
                ->update(['released_qty' => DB::raw('GREATEST(0, blocked_qty - used_qty)'), 'closed_at' => now()]);
        }
        if ($hotel) {
            AllotmentDay::where('allotment_id', $hotel->id)->whereDate('service_date', $retainedPast)
                ->update(['retained_reason' => 'Grupo corporativo Minera Andina pendiente de confirmar', 'retained_by' => $rosa->id, 'retained_at' => now()->subDays(12)]);
            // Retención vigente tras el release: expone a penalidad.
            $service->retain($service->day($hotel, $today->copy()->addDays(4)->toDateString()), 'Grupo Keller confirma pasajeros el viernes', $rosa);
        }

        // JOB-10 “de anoche”: libera fechas con release vencido no retenidas y cobra la fecha pasada retenida (CxP de penalidad).
        $result = app(ReleaseAllotments::class)->run();
        ScheduledJob::where('code', 'JOB-10')->update(['last_run_at' => $today->copy()->setTime(0, 15), 'last_status' => 'ok', 'last_result' => mb_substr($result['summary'], 0, 250)]);

        // Ampliaciones de PeruRail: una aceptada sobre fechas ya en release (queda la alerta) y otra pendiente del proveedor.
        if ($train = $contracts['SRV-001'] ?? null) {
            $accepted = $service->requestExpansion($train, ['date_from' => $today->copy()->addDays(8)->toDateString(), 'date_to' => $today->copy()->addDays(9)->toDateString(),
                'extra_qty' => 4, 'note' => 'Grupo Colegio San Agustín: 4 asientos adicionales'], $rosa);
            $service->respond($accepted, true, 'PeruRail confirma 4 asientos adicionales (correo de Karina Paredes)', $rosa);
            $service->requestExpansion($train, ['date_from' => $today->copy()->addDays(30)->toDateString(), 'date_to' => $today->copy()->addDays(34)->toDateString(),
                'extra_qty' => 6, 'note' => 'Feriado largo: proyección de ventas web +40 %'], $rosa);
        }
        if ($entries = $contracts['SRV-003'] ?? null) {
            $service->releaseDay($service->day($entries, $today->copy()->addDays(6)->toDateString()), 5, $rosa);
        }

        // El pick-up usa la fecha de venta: el consumo toma la fecha de creación de la reserva.
        foreach (AllotmentConsumption::with('reservation:id,created_at')->get() as $c) {
            if ($c->reservation?->created_at) {
                AllotmentConsumption::whereKey($c->id)->update(['created_at' => $c->reservation->created_at]);
            }
        }
    }

    private function travelDocs(User $rosa): void
    {
        $docs = app(TravelDocs::class);
        $docs->ensure($docs->upcoming()->get());
        $res = fn (string $code) => Reservation::where('code', $code)->first();
        $pax = fn (?Reservation $r, int $seq) => $r ? Passenger::where('reservation_id', $r->id)->where('seq', $seq)->first() : null;
        $item = fn (?Reservation $r, ?Passenger $p, string $type) => $r && $p ? $docs->item($r, $p->id, $type) : null;
        $upload = fn (?TravelDocItem $i, string $name, array $extra = []) => $i ? $docs->upload($i, ['file_name' => $name, 'mime_type' => 'application/pdf', 'content_base64' => self::PDF] + $extra, $rosa) : null;
        $validate = fn (?TravelDocItem $i, array $extra = []) => $i ? $docs->validate($i->fresh(), $extra, $rosa) : null;

        // RES-354 (en operación, John y Emily Miller, EE. UU.): pasaporte de John vence antes de fin de viaje + 6 meses (caso Johnson).
        if ($r = $res('RES-2026-000354')) {
            [$john, $emily] = [$pax($r, 1), $pax($r, 2)];
            $upload($item($r, $john, 'id_doc'), 'pasaporte-john-miller.pdf', ['doc_number' => '548812233', 'expiry_date' => '2027-03-10']);
            $upload($item($r, $john, 'insurance'), 'poliza-assist-card-john.pdf', ['doc_number' => 'AC-99812']);
            $validate($upload($item($r, $emily, 'id_doc'), 'pasaporte-emily-miller.pdf', ['doc_number' => '561200871', 'expiry_date' => '2031-06-30']));
            $validate($upload($item($r, $emily, 'insurance'), 'poliza-assist-card-emily.pdf', ['doc_number' => 'AC-99813']));
            $validate($upload($item($r, $emily, 'migration'), 'tam-emily.pdf'));
            foreach ([$john, $emily] as $p) {
                $p && $docs->contact($r, $p, ['name' => 'Robert Miller', 'phone' => '+1 512 555 0142', 'relation' => 'Familiar'], $rosa);
            }
        }

        // RES-359 (MAPI, Sol Viajes): Luis Vega es menor (16) → DNI por validar y permiso notarial pendiente: bloqueo MAPI.
        if ($r = $res('RES-2026-000359')) {
            [$ana, $luis] = [$pax($r, 1), $pax($r, 2)];
            Passenger::whereIn('id', array_filter([$ana?->id, $luis?->id]))->update(['nationality' => 'Peruana', 'doc_type' => 'DNI']);
            $luis?->forceFill(['birth_date' => '2010-05-14', 'relationship' => 'Hijo/a'])->save();
            $validate($upload($item($r, $ana, 'id_doc'), 'dni-ana-torres.pdf', ['doc_number' => '45678912', 'expiry_date' => '2031-02-11']));
            $upload($item($r, $luis, 'id_doc'), 'dni-luis-vega.pdf', ['doc_number' => '47896541']);
            $ana && $docs->contact($r, $ana, ['name' => 'Carmen Vega', 'phone' => '+51 984 551 203', 'relation' => 'Familiar'], $rosa);
            TravelDocReminder::create(['reservation_id' => $r->id, 'sent_at' => now()->subDay()->setTime(8, 0), 'sent_on' => now()->subDay()->toDateString(),
                'channels' => 'Email, WhatsApp', 'recipient' => 'b2b@solviajes.test', 'trigger' => 'auto', 'days_before' => 3, 'items_count' => 4]);
        }

        // RES-350 (MAPI, Ana Gutiérrez y Jorge Ríos, peruanos): DNI de Ana validado; el de Jorge observado por foto ilegible.
        if ($r = $res('RES-2026-000350')) {
            [$ana, $jorge] = [$pax($r, 1), $pax($r, 2)];
            $validate($upload($item($r, $ana, 'id_doc'), 'dni-ana-gutierrez.pdf', ['doc_number' => '45678912', 'expiry_date' => '2030-08-21']));
            if ($i = $upload($item($r, $jorge, 'id_doc'), 'dni-jorge-rios.pdf', ['doc_number' => '44321987'])) {
                $docs->observe($i, 'La foto del reverso está ilegible: cargue una imagen nítida de ambas caras.', $rosa);
            }
            $carla = User::where('code', 'U03')->first();
            TravelDocReminder::create(['reservation_id' => $r->id, 'sent_at' => now()->subDays(2)->setTime(11, 20), 'sent_on' => now()->subDays(2)->toDateString(),
                'channels' => 'Email', 'recipient' => 'cliente@correo.test', 'trigger' => 'manual', 'items_count' => 3, 'sent_by' => $carla?->id,
                'message' => 'Hola Ana, para emitir las entradas a Machu Picchu necesitamos los DNI de ambos.']);
        }

        // RES-361 y RES-366 (MAPI web): nacionalidad registrada, sin documentos todavía.
        foreach (['RES-2026-000361', 'RES-2026-000366'] as $code) {
            if ($r = $res($code)) {
                Passenger::where('reservation_id', $r->id)->whereNull('nationality')->update(['nationality' => 'Peruana']);
            }
        }

        // RES-356 (Colegio San Agustín): atención especial operativa (sin datos clínicos, DOC-BR-009).
        if (($r = $res('RES-2026-000356')) && ($p = $pax($r, 1))) {
            $docs->special($r, $p, ['requires_attention' => true, 'operational_note' => 'Usa silla de ruedas: traslados con rampa y asiento junto al pasillo.'], $rosa);
        }
        ScheduledJob::where('code', 'JOB-11')->update(['last_run_at' => app(CrmClock::class)->today()->setTime(8, 0), 'last_status' => 'ok', 'last_result' => '1 recordatorio(s) enviados']);
    }
}
