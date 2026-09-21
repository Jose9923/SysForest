<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tree;
use App\Models\User;
use App\Repositories\MapScopeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class MapTreeNavigationService
{
    public function __construct(
        private MapAccessService $access,
        private MapScopeRepository $scopes,
        private TenantContextService $tenants,
        private TenantContext $context,
    ) {
    }

    /** Solo un POST con CSRF persiste después la selección y redirige a la ficha privada. */
    public function open(User $actor, string $treeId, array $input = []): Tree
    {
        $selection = $input['scope'] ?? null;
        if (is_string($selection) && trim($selection) === '') {
            $selection = null;
        }
        $data = Validator::make(['scope' => $selection], [
            'scope' => ['nullable', 'string', Rule::in(['organization', 'department', 'national'])],
        ], [
            'string' => 'El alcance solicitado no es válido.',
            'in' => 'El alcance solicitado no es válido.',
        ])->validate();
        // Conserva la elección de la vista, pero vuelve a resolver sus concesiones actuales.
        $scope = $this->access->resolve($actor, $data['scope'] ?? null);
        $this->access->authorize($actor, $scope, 'trees.view');
        $tree = $this->scopes->findTree($scope, $treeId);

        try {
            $this->tenants->activate($actor, $tree->organization_id);
            Gate::forUser($actor)->authorize('view', $tree);
        } catch (Throwable $exception) {
            $this->context->clear();

            throw $exception;
        }

        return $tree;
    }
}
