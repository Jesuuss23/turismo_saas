<?php

namespace App\Http\Controllers\Api\Crm;

use App\Models\Crm\LeadQualification;
use App\Services\Crm\CrmCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catálogos del CRM y contexto de alcance para la UI (selects, chip "Mi cartera"). */
class CrmMetaController extends CrmController
{
    private const PERMISSIONS = [
        'leads_manage' => 'crm.leads.manage',
        'opportunities_manage' => 'crm.opportunities.manage',
        'activities_manage' => 'crm.activities.manage',
        'clients_manage' => 'crm.clients.manage',
        'quotes_create' => 'quotes.create',
    ];

    public function __invoke(Request $request, CrmCatalog $catalog): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json(['data' => [
            'catalogs' => $catalog->all(),
            'bant_labels' => LeadQualification::LABELS,
            'bant_levels' => LeadQualification::LEVELS,
            'scope' => [
                'own_only' => $this->access->ownOnly($user),
                'supervisor' => $this->access->isSupervisor($user),
            ],
            // Para ocultar/deshabilitar botones según §7.3 (la API valida igualmente).
            'can' => collect(self::PERMISSIONS)->mapWithKeys(fn ($perm, $key) => [$key => $user->hasPermissionTo($perm)]),
        ]]);
    }
}
