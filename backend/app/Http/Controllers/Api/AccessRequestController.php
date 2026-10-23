<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Iam\AccessRequest;
use App\Models\Iam\User;
use App\Security\AccessMap;
use App\Services\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** “Solicitar acceso al administrador” desde la pantalla 403 (§3.10). */
class AccessRequestController extends Controller
{
    public function store(Request $request, Notify $notify): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:250']]);
        /** @var User $user */
        $user = $request->user();
        $path = AccessMap::normalize($data['path']);

        $accessRequest = DB::transaction(function () use ($user, $path, $notify) {
            $accessRequest = AccessRequest::create([
                'user_id' => $user->getKey(),
                'path' => $path,
                'required_permissions' => AccessMap::required($path),
                'status' => 'pendiente',
                'created_at' => now(),
            ]);

            $admins = User::role('tenant_admin')->where('status', User::ACTIVE)->get();
            foreach ($admins as $admin) {
                $notify->push('acceso.solicitud', 'portal', $admin, "{$user->name} solicita acceso a /{$path}", null, [
                    'access_request_id' => $accessRequest->id,
                    'path' => $path,
                ]);
            }

            return $accessRequest;
        });

        return response()->json($this->present($accessRequest), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $items = AccessRequest::with('user:id,name,code')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('created_at')->limit(100)->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->present($r))]);
    }

    public function show(AccessRequest $accessRequest): JsonResponse
    {
        return response()->json($this->present($accessRequest->load('user:id,name,code')));
    }

    private function present(AccessRequest $r): array
    {
        return [
            'id' => $r->id,
            'path' => $r->path,
            'required_permissions' => $r->required_permissions,
            'status' => $r->status,
            'user' => $r->relationLoaded('user') ? $r->user?->only(['id', 'name', 'code']) : null,
            'created_at' => $r->created_at,
        ];
    }
}
