<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Enums\Organizations\OrganizationType;
use App\Http\Requests\Organizations\AdministrativeOrganizationRequest;
use App\Services\Organizations\OrganizationService;
use App\Services\Territories\TerritoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

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
