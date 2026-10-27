<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Marketing\Campaign;
use App\Models\Marketing\Consent;
use App\Models\Tenant;
use App\Services\Marketing\ConsentService;
use App\Services\Marketing\ContactDirectory;
use App\Services\Marketing\MessageRenderer;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Spec 14 §6: página de baja en un clic (token firmado, sin login; MKT-BR-02). Idempotente. */
class PublicUnsubscribeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(string $token): JsonResponse
    {
        return $this->within($token, function (array $t, ?Consent $consent, array $contact) {
            return response()->json(['data' => $this->payload($t, $consent, $contact)]);
        });
    }

    public function confirm(Request $request, string $token, ConsentService $consents): JsonResponse
    {
        return $this->within($token, function (array $t, ?Consent $consent, array $contact) use ($consents, $request) {
            if ($consent && $consent->status === 'otorgado') {
                $consent = $consents->revoke($consent, 'Enlace de baja', null, $t['m'] ? Campaign::find($t['m']) : null, $request->ip());
            }

            return response()->json(['data' => $this->payload($t, $consent, $contact), 'message' => 'Listo: ya no recibirás comunicaciones comerciales por este canal.']);
        });
    }

    private function within(string $token, callable $fn): JsonResponse
    {
        $t = MessageRenderer::decode($token) ?? throw ApiException::notFound();
        $tenant = Tenant::find($t['t']);
        if (! $tenant || ! $tenant->isActive()) {
            throw ApiException::notFound();
        }

        return $this->context->run($tenant, function () use ($t, $fn) {
            $contact = app(ContactDirectory::class)->find($t['s'], $t['i']) ?? throw ApiException::notFound();
            $consent = Consent::where('subject_type', $t['s'])->where('subject_id', $t['i'])->where('channel', $t['c'])->first();

            return $fn($t, $consent, $contact);
        });
    }

    private function payload(array $t, ?Consent $consent, array $contact): array
    {
        $email = $contact['email'] ?? '';
        $masked = $t['c'] === 'email' && $email ? preg_replace('/(?<=.).(?=[^@]*@)/u', '•', $email) : substr((string) ($contact['mobile'] ?? ''), 0, 4).'•••••'.substr((string) ($contact['mobile'] ?? ''), -3);

        return ['agency' => $this->context->tenant()?->name, 'channel' => $t['c'], 'channel_label' => ConsentService::CHANNELS[$t['c']] ?? $t['c'], 'name' => explode(' ', $contact['name'])[0],
            'address' => $masked, 'status' => $consent?->status ?? 'sin_consentimiento', 'revoked_at' => $consent?->revoked_at?->toIso8601String()];
    }
}
