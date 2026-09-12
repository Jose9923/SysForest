<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SwitchOrganizationRequest;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;

class TenantSelectionController extends Controller
{
    public function __invoke(SwitchOrganizationRequest $request, TenantContextService $service): RedirectResponse
    {
        $organization = $service->activate($request->user(), $request->validated('organization_id'));
        $request->session()->put('active_organization_id', $organization->id);

        return redirect()->route('organizations.show', $organization->id);
    }
}
