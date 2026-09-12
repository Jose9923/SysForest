<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Http\Requests\AdministrativeOrganizationRequest;
use App\Services\OrganizationService;
use App\Services\TerritoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationAdministrationController extends Controller
{
    public function edit(Request $request, string $organization, OrganizationService $organizations, TerritoryService $territories): View
    {
        return view('organizations.edit', [
            'organization' => $organizations->administrativeShow($request->user(), $organization),
            'municipalities' => $territories->municipalityOptions(),
            'types' => OrganizationType::cases(),
            'administrative' => true,
        ]);
    }

    public function update(AdministrativeOrganizationRequest $request, string $organization, OrganizationService $organizations): RedirectResponse
    {
        $organizations->administrativeUpdate($request->user(), $organization, $request->validated());

        return redirect()->route('organizations.index')->with('status', 'Organización actualizada desde el ámbito nacional.');
    }
}
