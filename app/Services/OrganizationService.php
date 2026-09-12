<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Repositories\DepartmentRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Tenancy\TenantContext;
use App\Validation\OrganizationRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    public function __construct(
        private OrganizationRepository $organizations,
        private OrganizationMembershipRepository $memberships,
        private DepartmentRepository $departments,
        private TenantContext $context,
        private TenantContextService $tenants,
    ) {
    }

    public function forActor(User $actor): LengthAwarePaginator
    {
        return $this->organizations->paginateForActor((int) $actor->id);
    }

    public function show(User $actor, string $id): Organization
    {
        $this->tenants->assertCurrentMembership($actor);

        if ($id !== $this->context->organizationId()) {
            throw (new ModelNotFoundException())->setModel(Organization::class);
        }

        $organization = $this->organizations->find($id);
        Gate::forUser($actor)->authorize('view', $organization);

        return $organization;
    }

    public function create(User $actor, array $input): Organization
    {
        Gate::forUser($actor)->authorize('create', Organization::class);
        $attributes = Validator::make($input, OrganizationRules::rules(), OrganizationRules::messages())->validate();

        try {
            return DB::transaction(function () use ($actor, $attributes): Organization {
                $organization = $this->organizations->create($attributes);
                // La creación institucional y la primera pertenencia se confirman juntas.
                $this->memberships->upsert(
                    (int) $actor->id,
                    $organization->id,
                    ['active' => true, 'joined_at' => now(), 'left_at' => null]
                );

                return $organization;
            });
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    public function update(User $actor, string $id, array $input): Organization
    {
        try {
            return DB::transaction(function () use ($actor, $id, $input): Organization {
                $this->tenants->assertCurrentMembership($actor, true);
                $organization = $this->show($actor, $id);
                Gate::forUser($actor)->authorize('update', $organization);
                $attributes = Validator::make($input, OrganizationRules::rules($id), OrganizationRules::messages())->validate();

                return $this->organizations->update($organization, $attributes);
            });
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    public function nationalRead(User $actor): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('organizations.view-national');

        return $this->organizations->paginateNational();
    }

    public function departmentRead(User $actor, string $departmentId): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('organizations.view-department', $departmentId);
        $this->departments->find($departmentId);

        return $this->organizations->paginateForDepartment($departmentId);
    }

    private function translateUniqueConflict(QueryException $exception): never
    {
        if ($exception->getCode() === '23505') {
            throw ValidationException::withMessages(['code' => 'Otro proceso reservó el código o prefijo. Utiliza uno diferente.']);
        }

        throw $exception;
    }
}
