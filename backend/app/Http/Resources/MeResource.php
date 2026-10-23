<?php

namespace App\Http\Resources;

use App\Models\Iam\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->primaryRole();
        $ux = $role?->ux($this->client_id);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_color' => $this->avatar_color,
            'role' => $role ? ['name' => $role->name, 'display_name' => $role->display_name, 'scope' => $role->scope] : null,
            'experience' => $ux['experience'] ?? 'backoffice',
            'experience_label' => $ux['label'] ?? null,
            'home' => $ux['home'] ?? 'dashboard',
            'scope' => $this->dataScope(),
            'scope_label' => match ($this->dataScope()) {
                'own' => 'Mi cartera',
                'linked' => 'Datos vinculados a su cuenta',
                default => 'Todo el equipo',
            },
            'sede' => $this->sede ? ['id' => $this->sede->id, 'name' => $this->sede->name] : null,
            'tenant' => ['id' => $this->tenant?->id, 'slug' => $this->tenant?->slug, 'name' => $this->tenant?->trade_name ?? $this->tenant?->legal_name],
            'mfa' => ['enabled' => $this->mfa_enabled, 'required' => $this->requiresMfa()],
            'last_login_at' => $this->last_login_at,
        ];
    }
}
