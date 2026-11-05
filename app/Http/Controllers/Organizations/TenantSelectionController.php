<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Requests\Organizations\SwitchOrganizationRequest;
use App\Services\Tenancy\TenantContextService;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;

class TenantSelectionController extends Controller
{
    public function __invoke(SwitchOrganizationRequest $request, TenantContextService $service): RedirectResponse
    {
        $organization = $service->activate($request->user(), $request->validated('organization_id'));
        $request->session()->put('active_organization_id', $organization->id);

        return redirect()->route('organizations.show', $organization->id);
    }
}
