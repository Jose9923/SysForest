<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Services\OrganizationService;
use App\Services\TerritoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationService $organizations, private TerritoryService $territories)
    {
    }

    public function index(Request $request): View
    {
        return view('organizations.index', ['organizations' => $this->organizations->forActor($request->user())]);
    }

    public function create(Request $request): View
    {
        Gate::forUser($request->user())->authorize('create', \App\Models\Organization::class);

        return view('organizations.create', ['municipalities' => $this->territories->municipalityOptions(), 'types' => OrganizationType::cases()]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $organization = $this->organizations->create($request->user(), $request->validated());
        if ($organization->active) {
            $request->session()->put('active_organization_id', $organization->id);

            return redirect()->route('organizations.show', $organization->id)->with('status', 'Organización creada.');
        }

        return redirect()->route('organizations.index')->with('status', 'Organización creada como inactiva.');
    }

    public function show(Request $request, string $organization): View
    {
        return view('organizations.show', ['organization' => $this->organizations->show($request->user(), $organization)]);
    }

    public function edit(Request $request, string $organization): View
    {
        $record = $this->organizations->show($request->user(), $organization);
        Gate::forUser($request->user())->authorize('update', $record);

        return view('organizations.edit', ['organization' => $record, 'municipalities' => $this->territories->municipalityOptions(), 'types' => OrganizationType::cases()]);
    }

    public function update(UpdateOrganizationRequest $request, string $organization): RedirectResponse
    {
        $this->organizations->update($request->user(), $organization, $request->validated());

        return redirect()->route('organizations.index')->with('status', 'Organización actualizada.');
    }
}
