<?php

namespace App\Services\Site;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Crm\Client;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Marketing\Consent;
use App\Models\Ops\Voucher;
use App\Security\RoleCatalog;
use App\Services\AuditLogger;
use App\Services\Auth\TokenIssuer;
use App\Services\Channels\ClientPortal;
use App\Services\Channels\EcomCheckout;
use App\Services\Crm\ClientService;
use App\Services\Marketing\ConsentService;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * «Mi cuenta» del sitio (spec 15 §6.5, SIT-BR-20/21): registro y acceso de clientes, reservas, vouchers, pagos pendientes (con montos en
 * validación), pago del saldo, lista de deseos, datos personales con boletín y solicitudes ARCO. Solo usuarios externos con `client_id`.
 */
class SiteAccount
{
    public function __construct(
        private readonly ClientPortal $portal,
        private readonly ClientService $clients,
        private readonly CartService $carts,
        private readonly EcomCheckout $ecom,
        private readonly TokenIssuer $tokens,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** SIT-BR-21: login del sitio (auditado; rate limit 5/min en la ruta); fusiona deseos locales y vincula el carrito. */
    public function login(array $data, ?string $ip): array
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user || ! $user->isActive() || ! Hash::check((string) ($data['password'] ?? ''), (string) $user->password)) {
            $this->audit->log('Sesión', $email ?: '(vacío)', 'login_fallido_sitio_web', null, "Credenciales inválidas · IP {$ip}", system: true);
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'SIT-BR-21', 'Correo o contraseña incorrectos.');
        }
        if (! $user->client_id || ! RoleCatalog::isExternal((string) $user->primaryRole()?->name)) {
            $this->audit->log('Sesión', $email, 'login_fallido_sitio_web', null, "Usuario sin cuenta de cliente · IP {$ip}", system: true);
            throw ApiException::forbidden('SIT-BR-20', 'Esta cuenta no tiene acceso a «Mi cuenta» del sitio. Use el back-office.');
        }

        return $this->session($user, $data, $ip, 'login_sitio_web');
    }

    /** SIT-BR-21: registro; vincula el cliente CRM con el mismo correo o crea uno (Persona, Retail, tags Web). */
    public function register(array $data, ?string $ip): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $errors = [];
        if (count(preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY)) < 2) {
            $errors['name'] = ['Indique nombres y apellidos.'];
        }
        if (! preg_match(EcomCheckout::EMAIL, $email)) {
            $errors['email'] = ['Ingrese un correo válido.'];
        } elseif (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            $errors['email'] = ['Ya existe una cuenta con ese correo. Ingrese o recupere su contraseña.'];
        }
        if (strlen(preg_replace('/\D/', '', (string) ($data['phone'] ?? ''))) < 7) {
            $errors['phone'] = ['Ingrese un celular válido.'];
        }
        if ($err = SiteCheckout::docError((string) ($data['doc_type'] ?? ''), (string) ($data['doc_number'] ?? ''))) {
            $errors['doc_number'] = [$err];
        }
        if (mb_strlen((string) ($data['password'] ?? '')) < 8) {
            $errors['password'] = ['La contraseña debe tener al menos 8 caracteres.'];
        }
        if (! filter_var($data['privacy'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $errors['privacy'] = ['Acepte el tratamiento de sus datos personales (Ley 29733).'];
        }
        if ($errors) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'VALIDATION_ERROR', reset($errors)[0], [], ['errors' => $errors]);
        }
        $owner = $this->ecom->owner();
        $user = DB::transaction(function () use ($name, $email, $data, $owner) {
            $client = Client::where('email_norm', $email)->orderBy('created_at')->first();
            if (! $client) {
                $docType = ['DNI' => 'DNI', 'CE' => 'CE', 'Pasaporte' => 'PASAPORTE'][$data['doc_type']] ?? 'DNI';
                $doc = strtoupper(trim((string) $data['doc_number']));
                $client = Client::where('doc_number', $doc)->first() ?? $this->clients->create(['kind' => 'persona', 'name' => $name, 'doc_type' => $docType, 'doc_number' => $doc,
                    'email' => $email, 'phone' => $data['phone'], 'country' => $data['country'] ?? 'Perú', 'segment_code' => 'retail', 'tags' => ['Web', 'Sitio web'], 'owner_id' => $owner->id], $owner, true);
                $this->audit->log('Cliente', $client->code, 'crear_desde_registro_web', null, $email, system: true);
            }
            $user = User::create(['code' => 'WEB-U'.Str::upper(Str::random(5)), 'name' => $name, 'email' => $email, 'password' => (string) $data['password'],
                'phone' => $data['phone'], 'status' => User::ACTIVE, 'sede_id' => DB::table('iam_sedes')->where('is_main', true)->value('id'), 'client_id' => $client->id,
                'created_via' => 'sitio_web']);
            $user->assignRole('customer');
            $this->audit->log('Usuario', $email, 'registro_cliente_web', null, "{$client->code} · {$client->name}", system: true);

            return $user;
        });
        $this->notify->push('cuenta.bienvenida', 'email', $email, 'Bienvenido a '.($this->tenant->tenant()?->trade_name ?? 'la agencia'), 'Su cuenta está lista: revise sus reservas y pagos en Mi cuenta.',
            ['client_id' => $user->client_id]);
        if (filter_var($data['newsletter'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->setNewsletter(Client::find($user->client_id), true, $user);
        }

        return $this->session($user, $data, null, 'login_sitio_web');
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
        $this->audit->log('Sesión', $user->email, 'logout', null, 'Sitio web', actor: $user);
    }

    /** SIT-BR-20: solo usuarios externos con cliente vinculado. */
    public function clientOf(User $user): Client
    {
        if (! $user->client_id || ! RoleCatalog::isExternal((string) $user->primaryRole()?->name) || ! ($client = Client::find($user->client_id))) {
            throw ApiException::forbidden('SIT-BR-20', 'Su usuario no tiene una cuenta de cliente.');
        }

        return $client;
    }

    public function me(User $user): array
    {
        $client = $this->clientOf($user);
        $consent = Consent::where('subject_type', 'client')->where('subject_id', $client->id)->where('channel', 'email')->first();

        return ['name' => $user->name, 'email' => $user->email, 'phone' => $client->phone ?? $user->phone, 'client' => ['code' => $client->code, 'name' => $client->name,
            'doc_type' => $client->doc_type, 'doc_number' => $client->doc_number, 'country' => $client->country, 'email' => $client->email],
            'newsletter' => $consent?->status === 'otorgado', 'lock_version' => $client->lock_version, 'portal_url' => '/portal'];
    }

    /** PATCH /public/me (auditado): nombre, celular, país y boletín. */
    public function update(User $user, array $data): array
    {
        $client = $this->clientOf($user);
        $fill = [];
        if (isset($data['phone'])) {
            if (strlen(preg_replace('/\D/', '', (string) $data['phone'])) < 7) {
                throw ApiException::invalid('phone', 'Ingrese un celular válido.');
            }
            $fill['phone'] = trim((string) $data['phone']);
        }
        if (isset($data['country']) && trim((string) $data['country']) !== '') {
            $fill['country'] = mb_substr(trim((string) $data['country']), 0, 60);
        }
        if ($fill) {
            $before = "{$client->phone} · {$client->country}";
            $client->forceFill($fill)->save();
            $this->audit->log('Cliente', $client->code, 'actualizar_datos_sitio', $before, "{$client->phone} · {$client->country}", actor: $user);
        }
        if (array_key_exists('newsletter', $data)) {
            $this->setNewsletter($client, filter_var($data['newsletter'], FILTER_VALIDATE_BOOLEAN), $user);
        }

        return $this->me($user->fresh());
    }

    public function reservations(User $user): array
    {
        $client = $this->clientOf($user);
        $balances = Receivable::where('client_id', $client->id)->whereIn('status', Receivable::OPEN)->get()->groupBy('reservation_id');

        return array_map(fn ($r) => $r + ['balance' => (string) collect($balances[Reservation::where('code', $r['code'])->value('id')] ?? [])->sum(fn ($x) => (float) $x->balance())],
            $this->portal->reservations($client));
    }

    public function reservation(User $user, string $code): array
    {
        return $this->portal->reservation($this->clientOf($user), $code);
    }

    public function vouchers(User $user): array
    {
        $client = $this->clientOf($user);
        $res = Reservation::where('client_id', $client->id)->pluck('code', 'id');

        return Voucher::whereIn('reservation_id', $res->keys())->where('status', '<>', Voucher::VOIDED)->orderBy('service_date')->get()
            ->map(fn (Voucher $v) => ['code' => $v->code, 'service' => $v->service_label, 'date' => $v->service_date->toDateString(), 'reservation' => $res[$v->reservation_id],
                'pax' => $v->pax, 'version' => $v->version, 'status_label' => Voucher::STATUS_LABELS[$v->status]])->all();
    }

    /** SIT-BR-20: CxC abiertas/vencidas con montos en validación; «Pagar» solo si queda saldo no cubierto por pagos por validar. */
    public function receivables(User $user, bool $openOnly = true): array
    {
        $client = $this->clientOf($user);
        $rows = collect($this->portal->receivables($client))->when($openOnly, fn ($c) => $c->filter(fn ($r) => in_array($r['status'], Receivable::OPEN, true)));
        $pending = Payment::whereIn('receivable_id', $rows->pluck('id'))->where('status', 'pendiente')->groupBy('receivable_id')->selectRaw('receivable_id, sum(amount) as s')->pluck('s', 'receivable_id');

        return $rows->map(function ($r) use ($pending) {
            $p = (float) ($pending[$r['id']] ?? 0);
            $payable = max(0, round((float) $r['balance'] - $p, 2));

            return ['pending_validation' => number_format($p, 2, '.', ''), 'payable' => number_format($payable, 2, '.', ''), 'can_pay' => $r['can_pay'] && $payable > 0] + $r;
        })->values()->all();
    }

    /** POST /public/me/receivables/{id}/payments: paga el saldo con tarjeta; emite vouchers si todo queda pagado (SIT-BR-18/20). */
    public function pay(User $user, string $receivableId, array $data): array
    {
        $client = $this->clientOf($user);
        $row = collect($this->receivables($user))->firstWhere('id', $receivableId) ?? throw ApiException::notFound();
        if (! $row['can_pay']) {
            throw ApiException::rule('SIT-BR-20', 'Esta obligación no tiene saldo por pagar (hay un pago en validación que la cubre).');
        }

        return $this->portal->pay($client, $receivableId, ['amount' => $row['payable'], 'card' => (array) ($data['card'] ?? $data)], $user);
    }

    public function wishlist(User $user): array
    {
        $client = $this->clientOf($user);
        $ids = DB::table('channel_wishlist_items')->where('tenant_id', $this->tenant->id())->where('crm_client_id', $client->id)->orderByDesc('created_at')->pluck('product_id');

        return Product::whereIn('id', $ids)->get()->map(fn (Product $p) => ['id' => $p->id, 'slug' => SiteCatalog::slugOf($p), 'name' => $p->name])->values()->all();
    }

    public function wish(User $user, string $slug, bool $on): array
    {
        $client = $this->clientOf($user);
        $product = Product::where('slug', $slug)->orWhere('ref', $slug)->first() ?? Product::all()->first(fn ($p) => SiteCatalog::slugOf($p) === $slug) ?? throw ApiException::notFound();
        $this->toggle($client, [$product->id], $on);

        return $this->wishlist($user);
    }

    /** POST /public/me/arco-requests: solicitud ARCO como ticket del cliente (Pagos/consulta, plazo legal 20 días hábiles). */
    public function arco(User $user, array $data): array
    {
        $client = $this->clientOf($user);
        $types = ['acceso' => 'Acceso', 'rectificacion' => 'Rectificación', 'cancelacion' => 'Cancelación', 'oposicion' => 'Oposición'];
        $type = $types[$data['type'] ?? ''] ?? throw ApiException::invalid('type', 'Elija el derecho ARCO que desea ejercer.');
        $detail = trim((string) ($data['detail'] ?? ''));
        if (mb_strlen($detail) < 10) {
            throw ApiException::invalid('detail', 'Describa su solicitud (mínimo 10 caracteres).');
        }
        $ticket = $this->portal->createTicket($client, ['subject' => "Solicitud ARCO — {$type}", 'message' => $detail, 'category' => 'Consulta general'], $user);
        $this->audit->log('Cliente', $client->code, 'solicitud_arco', null, "{$type} · {$ticket->code}", actor: $user);

        return ['ticket' => $ticket->code, 'type' => $type, 'message' => 'Recibimos su solicitud. Responderemos dentro del plazo legal (20 días hábiles).'];
    }

    private function session(User $user, array $data, ?string $ip, string $action): array
    {
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();
        $token = $this->tokens->issue($user, false, 'sitio-web', $ip);
        $this->audit->log('Sesión', $user->email, $action, '', 'Sitio web', actor: $user);
        $client = Client::find($user->client_id);
        if ($client && ! empty($data['wishlist']) && is_array($data['wishlist'])) {
            $ids = $this->published($data['wishlist']);
            $this->toggle($client, $ids, true);
        }
        if ($client && ($cart = $this->carts->find($data['cart_token'] ?? null)) && ! $cart->crm_client_id) {
            $cart->forceFill(['crm_client_id' => $client->id, 'email' => $client->email])->save();
        }

        return ['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at, 'user' => ['name' => $user->name, 'email' => $user->email,
            'client' => $client?->code]];
    }

    /** @return list<string> ids de productos a partir de slugs/refs/ids */
    private function published(array $keys): array
    {
        $keys = array_slice(array_map('strval', $keys), 0, 50);

        return Product::all()->filter(fn (Product $p) => in_array($p->id, $keys, true) || in_array($p->ref, $keys, true) || in_array(SiteCatalog::slugOf($p), $keys, true))->pluck('id')->values()->all();
    }

    private function toggle(Client $client, array $productIds, bool $on): void
    {
        $q = DB::table('channel_wishlist_items')->where('tenant_id', $this->tenant->id())->where('crm_client_id', $client->id);
        if (! $on) {
            $q->whereIn('product_id', $productIds)->delete();

            return;
        }
        foreach ($productIds as $id) {
            DB::table('channel_wishlist_items')->insertOrIgnore(['tenant_id' => $this->tenant->id(), 'crm_client_id' => $client->id, 'product_id' => $id, 'created_at' => now()]);
        }
    }

    private function setNewsletter(Client $client, bool $on, User $actor): void
    {
        $consents = app(ConsentService::class);
        $current = Consent::where('subject_type', 'client')->where('subject_id', $client->id)->where('channel', 'email')->first();
        if ($on && $current?->status !== 'otorgado') {
            $consents->grant(['subject_type' => 'client', 'subject_id' => $client->id, 'channel' => 'email', 'source' => 'Formulario web (casilla)',
                'evidence' => 'Mi cuenta del sitio web · casilla no premarcada', 'policy_version' => SiteCheckout::POLICY_VERSION], $actor);
        } elseif (! $on && $current?->status === 'otorgado') {
            $consents->revoke($current, 'Portal del cliente', $actor);
        }
        $client->forceFill(['consents' => ['email' => $on ? 'otorgado' : 'revocado', 'at' => now()->toIso8601String(), 'policy_version' => SiteCheckout::POLICY_VERSION]])->save();
    }
}
