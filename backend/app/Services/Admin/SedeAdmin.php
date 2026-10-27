<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Platform\LimitService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Sedes (spec 09 §4.3, SED-BR-01…05): límite por plan, responsable válido y sin borrar con referencias. */
class SedeAdmin
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly UserAdmin $users,
    ) {}

    public function list(): array
    {
        $users = DB::table('iam_users')->whereNull('deleted_at')->where('status', User::ACTIVE)->selectRaw('sede_id, count(*) as n')->groupBy('sede_id')->pluck('n', 'sede_id');
        $reservations = DB::table('booking_reservations')->whereNull('deleted_at')->selectRaw('sede_id, count(*) as n')->groupBy('sede_id')->pluck('n', 'sede_id');
        $tenant = Tenant::with('plan')->findOrFail($this->context->id());

        return [
            'data' => Sede::with('manager')->orderBy('code')->get()->map(fn (Sede $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'city' => $s->city, 'address' => $s->address, 'phone' => $s->phone,
                'manager_user_id' => $s->manager_user_id, 'manager' => $s->manager?->name, 'status' => $s->status, 'is_main' => (bool) $s->is_main,
                'active_users' => (int) ($users[$s->id] ?? 0), 'reservations' => (int) ($reservations[$s->id] ?? 0), 'lock_version' => (int) $s->lock_version,
            ])->all(),
            'meta' => [
                'max_sedes' => $tenant->plan?->max_sedes, 'plan' => $tenant->plan?->name, 'active' => Sede::where('status', 'activa')->count(),
                'managers' => $this->users->managers()->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
            ],
        ];
    }

    public function create(array $input, User $actor): Sede
    {
        $data = $this->validate($input);
        $this->assertUniqueName($data['name']);

        return DB::transaction(function () use ($data, $actor) {
            Tenant::whereKey($this->context->id())->lockForUpdate()->first();
            // Spec 13 SUB-BR-04: límite efectivo del plan → 422 PLAN_LIMIT_REACHED.
            app(LimitService::class)->assertWithin($this->context->id(), 'sedes', 1, 'SED-BR-02');
            $n = (int) Sede::withTrashed()->get(['code'])->map(fn ($s) => (int) preg_replace('/\D/', '', $s->code))->max();
            $sede = Sede::create($data + ['code' => sprintf('S%02d', $n + 1), 'status' => 'activa', 'is_main' => false, 'created_by' => $actor->id]);
            $this->audit->log('Sede', $sede->code, 'crear', null, "{$sede->name} · {$sede->address}", actor: $actor);

            return $sede;
        });
    }

    public function update(Sede $sede, array $input, User $actor): Sede
    {
        $data = $this->validate($input, true);
        if (isset($data['name']) && mb_strtolower($data['name']) !== mb_strtolower($sede->name)) {
            $this->assertUniqueName($data['name'], $sede->id);
        }
        $before = collect($data)->map(fn ($v, $k) => $sede->{$k})->filter(fn ($v, $k) => $v !== $data[$k]);
        if ($before->isEmpty()) {
            return $sede;
        }
        $sede->fill($data + ['updated_by' => $actor->id])->save();
        $this->audit->log('Sede', $sede->code, 'editar', $before->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '),
            $before->keys()->map(fn ($k) => "{$k}: {$data[$k]}")->implode(' · '), actor: $actor);

        return $sede;
    }

    /** SED-BR-03: sin usuarios activos y no la última activa (ni la principal). */
    public function deactivate(Sede $sede, User $actor): Sede
    {
        if ($sede->status === 'inactiva') {
            throw ApiException::rule('SED-STATE', 'La sede ya está inactiva.');
        }
        $users = User::where('sede_id', $sede->id)->where('status', User::ACTIVE)->count();
        if ($users > 0) {
            throw ApiException::rule('SED-BR-03', "La sede tiene {$users} usuario(s) activo(s): reasígnelos antes de desactivarla.");
        }
        if (Sede::where('status', 'activa')->count() <= 1) {
            throw ApiException::rule('SED-BR-03', 'El tenant debe conservar al menos una sede activa.');
        }
        if ($sede->is_main) {
            throw ApiException::rule('SED-BR-03', 'Es la sede principal: elija otra en Configuración › General antes de desactivarla.');
        }
        $sede->fill(['status' => 'inactiva', 'updated_by' => $actor->id])->save();
        $this->audit->log('Sede', $sede->code, 'desactivar', 'activa', 'inactiva', actor: $actor);

        return $sede;
    }

    public function activate(Sede $sede, User $actor): Sede
    {
        if ($sede->status === 'activa') {
            throw ApiException::rule('SED-STATE', 'La sede ya está activa.');
        }
        app(LimitService::class)->assertWithin($this->context->id(), 'sedes', 1, 'SED-BR-02');
        $sede->fill(['status' => 'activa', 'updated_by' => $actor->id])->save();
        $this->audit->log('Sede', $sede->code, 'activar', 'inactiva', 'activa', actor: $actor);

        return $sede;
    }

    /** SED-BR-04: borrado físico solo sin usuarios ni reservas. */
    public function delete(Sede $sede, User $actor): void
    {
        $users = User::withTrashed()->where('sede_id', $sede->id)->count();
        $reservations = DB::table('booking_reservations')->where('sede_id', $sede->id)->count();
        if ($users > 0 || $reservations > 0 || $sede->is_main) {
            throw ApiException::rule('SED-BR-04', "La sede tiene {$users} usuario(s) y {$reservations} reserva(s) asociadas. Desactívela para conservar el historial.");
        }
        DB::transaction(function () use ($sede, $actor) {
            $this->audit->log('Sede', $sede->code, 'eliminar', $sede->name, null, actor: $actor);
            $sede->forceDelete();
        });
    }

    private function validate(array $input, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $data = Validator::make($input, [
            'name' => "{$req}|string|max:120", 'address' => "{$req}|string|max:250", 'city' => 'sometimes|nullable|string|max:80',
            'phone' => 'sometimes|nullable|string|max:30', 'manager_user_id' => 'sometimes|nullable|uuid',
        ], ['address.required' => 'La dirección es obligatoria.', 'name.required' => 'El nombre de la sede es obligatorio.'])->validate();
        if (! empty($data['manager_user_id']) && ! $this->users->managers()->contains('id', $data['manager_user_id'])) {
            throw ApiException::invalid('manager_user_id', 'El responsable debe ser un usuario activo con rol Tenant Admin, Manager, Ventas, Operaciones o Finanzas.');
        }

        return $data;
    }

    private function assertUniqueName(string $name, ?string $except = null): void
    {
        if (Sede::whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ApiException::unprocessable('SED-BR-01', 'Ya existe una sede con ese nombre.', ['errors' => ['name' => ['Nombre de sede duplicado.']]]);
        }
    }
}
