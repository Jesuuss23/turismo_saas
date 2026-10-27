<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Channels\ClientPortal;
use App\Services\Crm\ClaimBookPresenter;
use App\Services\Crm\ClaimBookService;
use App\Services\Crm\ClaimCertificate;
use App\Services\Ops\PassengerApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 12 §6: hoja de reclamación virtual desde el portal del cliente, el del pasajero y el sitio web público.
 * El cliente y la reserva salen de la sesión (nunca del cuerpo); «Mis hojas» solo muestra las propias (LR-BR-09).
 * Un interno con `crm.claims.manage` ve el formulario como vista previa, sin registrar.
 */
class ClaimBookPortalController extends Controller
{
    public function __construct(
        private readonly ClaimBookService $service,
        private readonly ClaimBookPresenter $present,
        private readonly ClientPortal $clients,
        private readonly PassengerApp $passengers,
    ) {}

    public function form(Request $request): JsonResponse
    {
        $ctx = $this->context($request->user());

        return response()->json(['data' => $this->formData() + [
            'mode' => $ctx['mode'], 'prefill' => $ctx['prefill'], 'reservation' => $ctx['reservation']?->code,
            'mine' => $ctx['mode'] === 'preview' ? [] : $this->mine($request->user())->with('sede:id,name')->orderByDesc('received_at')->get()->map(fn ($c) => $this->present->portalRow($c))->all(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $ctx = $this->context($request->user());

        return response()->json(['data' => $ctx['mode'] === 'preview' ? []
            : $this->mine($request->user())->with('sede:id,name')->orderByDesc('received_at')->get()->map(fn ($c) => $this->present->portalRow($c))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $ctx = $this->context($request->user());
        if ($ctx['mode'] === 'preview') {
            throw ApiException::forbidden('LR-PREVIEW', 'Vista previa interna: el formulario no registra hojas. Use el registro presencial.');
        }
        $channel = $ctx['mode'] === 'passenger' ? 'passenger_portal' : 'customer_portal';
        $c = $this->service->register($request->all(), $channel, $request->user(), $ctx['client'], $ctx['reservation']);

        return response()->json(['data' => $this->present->portalRow($c->load('sede')), 'message' => "Hoja {$c->book_number} registrada. Le enviamos la constancia a {$c->consumer['email']}."], 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->present->portalRow($this->own($request->user(), $code))]);
    }

    public function certificate(Request $request, string $code, ClaimCertificate $pdf, AuditLogger $audit): Response
    {
        $c = $this->own($request->user(), $code);
        $audit->log('Libro de reclamaciones', $c->book_number, 'descargar_constancia', null, 'Portal', actor: $request->user());

        return response($pdf->pdf($c), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"constancia-{$c->book_number}.pdf\""]);
    }

    /** Sitio web público: datos del formulario y un captcha aritmético firmado (simulado; 10 minutos). */
    public function publicForm(): JsonResponse
    {
        [$a, $b] = [random_int(1, 9), random_int(1, 9)];

        return response()->json(['data' => $this->formData() + ['captcha' => ['question' => "¿Cuánto es {$a} + {$b}?",
            'token' => Crypt::encryptString(json_encode(['sum' => $a + $b, 'exp' => now()->addMinutes(10)->timestamp]))]]]);
    }

    public function publicStore(Request $request): JsonResponse
    {
        $this->assertCaptcha((string) $request->input('captcha_token'), (string) $request->input('captcha_answer'));
        $c = $this->service->register($request->all(), 'web');

        return response()->json(['data' => ['book_number' => $c->book_number, 'received_at' => $c->received_at?->toIso8601String(), 'due_date' => $c->due_date?->toDateString()],
            'message' => "Hoja {$c->book_number} registrada. Le enviamos la constancia a {$c->consumer['email']}."], 201);
    }

    private function formData(): array
    {
        return ['sedes' => $this->service->sedes()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(), 'doc_types' => ClaimBookService::DOC_TYPES,
            'response_days' => $this->service->settings()->response_days, 'provider' => $this->service->provider()];
    }

    /** @return array{mode: string, client: ?Client, reservation: ?Reservation, prefill: array} */
    private function context(User $user): array
    {
        if (! $user->isExternal()) {
            if (! $user->hasPermissionTo('crm.claims.manage')) {
                throw ApiException::forbidden('LR-BR-09', 'Solo el consumidor o el equipo del libro de reclamaciones pueden ver esta página.');
            }

            return ['mode' => 'preview', 'client' => null, 'reservation' => null, 'prefill' => ['name' => 'Consumidor de ejemplo', 'doc_type' => 'DNI', 'doc_number' => '',
                'email' => 'consumidor@ejemplo.pe', 'phone' => '', 'address' => '', 'good' => '']];
        }
        if ($user->hasRole('customer')) {
            $client = $this->clients->clientOf($user);

            return ['mode' => 'customer', 'client' => $client, 'reservation' => null, 'prefill' => ['name' => $client->name, 'doc_type' => in_array($client->doc_type, ClaimBookService::DOC_TYPES, true) ? $client->doc_type : 'DNI',
                'doc_number' => $client->doc_number, 'email' => $client->email ?: $user->email, 'phone' => $client->phone, 'address' => $client->address ?? '', 'good' => '']];
        }
        if ($user->reservation_id) {
            $r = $this->passengers->reservationFor($user);
            $pax = Passenger::where('reservation_id', $r->id)->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])->first()
                ?? Passenger::where('reservation_id', $r->id)->where('is_lead', true)->first();

            return ['mode' => 'passenger', 'client' => $r->client_id ? Client::find($r->client_id) : null, 'reservation' => $r, 'prefill' => [
                'name' => $pax?->full_name ?? $user->name, 'doc_type' => in_array($pax?->doc_type, ClaimBookService::DOC_TYPES, true) ? $pax->doc_type : 'DNI',
                'doc_number' => $pax?->doc_number ?? '', 'email' => $user->email, 'phone' => $pax?->phone ?? '', 'address' => '',
                'good' => "Reserva {$r->code}".($r->title ? " · {$r->title}" : ''), 'reservation' => $r->code]];
        }
        throw ApiException::forbidden('LR-BR-09', 'Su usuario no tiene acceso al libro de reclamaciones.');
    }

    /** LR-BR-09: hojas propias (enviadas por el usuario, de su cuenta de cliente o a su correo). */
    private function mine(User $user): Builder
    {
        $clientId = $user->hasRole('customer') ? $user->client_id : null;

        return AfterSalesCase::where('is_book', true)->where(fn ($q) => $q->where('submitted_by', $user->id)
            ->orWhere('consumer_email', mb_strtolower($user->email))
            ->when($clientId, fn ($q) => $q->orWhere('client_id', $clientId)));
    }

    private function own(User $user, string $code): AfterSalesCase
    {
        $this->context($user);
        $key = mb_strtoupper($code);

        return $this->mine($user)->with('sede:id,name')->where(fn ($q) => $q->where('code', $key)->orWhere('book_number', $key))->first() ?? throw ApiException::notFound();
    }

    private function assertCaptcha(string $token, string $answer): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            $payload = null;
        }
        if (! is_array($payload) || ($payload['exp'] ?? 0) < now()->timestamp || (int) trim($answer) !== (int) $payload['sum']) {
            throw ApiException::invalid('captcha_answer', 'Resuelva la verificación antispam.');
        }
    }
}
