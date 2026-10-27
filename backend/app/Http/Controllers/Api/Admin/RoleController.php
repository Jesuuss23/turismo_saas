<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\RoleAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Roles y permisos (spec 09 §6.3). */
class RoleController extends Controller
{
    public function __construct(private readonly RoleAdmin $roles) {}

    public function index(): JsonResponse
    {
        return response()->json($this->roles->list());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->roles->create($request->all(), $request->user()), 'message' => 'Rol creado'], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        return response()->json(['data' => $this->roles->update($this->roles->find($role), $request->all(), $request->user()), 'message' => 'Rol actualizado']);
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        $this->roles->delete($this->roles->find($role), $request->user());

        return response()->json(['message' => 'Rol eliminado']);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => $this->roles->catalog()]);
    }

    public function matrix(): JsonResponse
    {
        return response()->json(['data' => $this->roles->matrix(), 'audit' => $this->roles->permissionAudit()]);
    }

    public function grant(Request $request, string $role, string $permission): JsonResponse
    {
        return response()->json(['data' => $this->roles->grant($this->roles->find($role), $permission, $request->user()), 'message' => "Permiso {$permission} otorgado"]);
    }

    public function revoke(Request $request, string $role, string $permission): JsonResponse
    {
        return response()->json(['data' => $this->roles->revoke($this->roles->find($role), $permission, $request->user()), 'message' => "Permiso {$permission} revocado"]);
    }

    public function bulk(Request $request, string $role): JsonResponse
    {
        $data = $request->validate(['module' => 'required|string', 'grant' => 'required|boolean']);

        return response()->json($this->roles->bulk($this->roles->find($role)->load('permissions'), $data['module'], (bool) $data['grant'], $request->user()));
    }

    public function accessMap(): JsonResponse
    {
        return response()->json(['data' => $this->roles->accessMap()]);
    }
}
