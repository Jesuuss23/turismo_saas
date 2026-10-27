<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Services\Admin\SedeAdmin;
use App\Services\Admin\UserAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Usuarios y sedes (PROC-002, spec 09 §6.2). */
class UserController extends Controller
{
    public function __construct(private readonly UserAdmin $users, private readonly SedeAdmin $sedes) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->users->list($request->only(['role', 'sede', 'status', 'mfa', 'q'])));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->users->invite($request->all(), $request->user()), 201);
    }

    public function show(Request $request, string $user): JsonResponse
    {
        return response()->json(['data' => $this->users->detail($this->user($user), $request->user())]);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        return response()->json($this->users->update($this->user($user), $request->all(), $request->header('If-Match'), $request->user()));
    }

    public function deactivate(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'El motivo es obligatorio.']);

        return response()->json($this->users->deactivate($this->user($user), $data['reason'], $request->user()));
    }

    public function activate(Request $request, string $user): JsonResponse
    {
        return response()->json($this->users->activate($this->user($user), $request->user()));
    }

    public function resetMfa(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'El motivo es obligatorio.']);

        return response()->json($this->users->resetMfa($this->user($user), $data['reason'], $request->user()));
    }

    public function resendInvitation(Request $request, string $user): JsonResponse
    {
        return response()->json($this->users->resendInvitation($this->user($user), $request->user()));
    }

    // ── Sedes ──

    public function sedes(): JsonResponse
    {
        return response()->json($this->sedes->list());
    }

    public function storeSede(Request $request): JsonResponse
    {
        $sede = $this->sedes->create($request->all(), $request->user());

        return response()->json(['data' => $sede->only(['id', 'code', 'name']), 'message' => "Sede {$sede->name} creada"], 201);
    }

    public function updateSede(Request $request, string $sede): JsonResponse
    {
        $model = $this->sede($sede);
        $model->assertIfMatch($request);
        $model = $this->sedes->update($model, $request->all(), $request->user());

        return response()->json(['data' => $model->only(['id', 'code', 'name', 'lock_version']), 'message' => 'Sede actualizada']);
    }

    public function sedeStatus(Request $request, string $sede, string $action): JsonResponse
    {
        $model = $action === 'activate' ? $this->sedes->activate($this->sede($sede), $request->user()) : $this->sedes->deactivate($this->sede($sede), $request->user());

        return response()->json(['data' => $model->only(['id', 'code', 'status']), 'message' => $action === 'activate' ? 'Sede activada' : 'Sede desactivada']);
    }

    public function destroySede(Request $request, string $sede): JsonResponse
    {
        $this->sedes->delete($this->sede($sede), $request->user());

        return response()->json(['message' => 'Sede eliminada']);
    }

    private function user(string $key): User
    {
        return (Str::isUuid($key) ? User::whereKey($key) : User::where('code', $key))->first() ?? throw ApiException::notFound();
    }

    private function sede(string $key): Sede
    {
        return (Str::isUuid($key) ? Sede::whereKey($key) : Sede::where('code', $key))->first() ?? throw ApiException::notFound();
    }
}
