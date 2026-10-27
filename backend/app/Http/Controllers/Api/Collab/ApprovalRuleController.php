<?php

namespace App\Http\Controllers\Api\Collab;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Collab\ApprovalRule;
use App\Models\Iam\Permission;
use App\Services\Collab\ApprovalPresenter;
use App\Services\Collab\ApprovalRules;
use App\Services\Collab\CollabCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Spec 10 §6: matriz de reglas. Lectura con approvals.view; escritura con admin.settings.manage (§7). */
class ApprovalRuleController extends Controller
{
    public function __construct(private readonly ApprovalRules $rules, private readonly ApprovalPresenter $present) {}

    public function index(Request $request): JsonResponse
    {
        $rows = ApprovalRule::orderByRaw('array_position(ARRAY['.implode(',', array_map(fn ($t) => "'{$t}'", array_keys(CollabCatalog::TYPES))).']::varchar[], request_type)')
            ->orderBy('level')->orderBy('min_amount_pen')->get();

        return response()->json(['data' => $rows->map(fn (ApprovalRule $r) => $this->present->rule($r))->all(), 'meta' => [
            'can_edit' => $request->user()->checkPermissionTo('admin.settings.manage'), 'types' => CollabCatalog::TYPES,
            'origin_permissions' => CollabCatalog::ORIGIN_PERMISSIONS,
            'permissions' => Permission::where('name', 'not like', 'supplier.%')->orderBy('name')->pluck('name')->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->rule($this->rules->create($request->all(), $request->user()))], 201);
    }

    public function update(Request $request, string $rule): JsonResponse
    {
        return response()->json(['data' => $this->present->rule($this->rules->update($this->find($rule), $request->all(), $request->user(), $request))]);
    }

    public function destroy(Request $request, string $rule): JsonResponse
    {
        $this->rules->delete($this->find($rule), $request->user(), $request);

        return response()->json(null, 204);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->rules->reset($request->user());

        return $this->index($request);
    }

    private function find(string $rule): ApprovalRule
    {
        return ApprovalRule::where('code', $rule)->orWhere(fn ($q) => preg_match('/^[0-9a-f-]{36}$/i', $rule) ? $q->where('id', $rule) : $q->whereRaw('false'))->first()
            ?? throw ApiException::notFound();
    }
}
