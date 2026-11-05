<?php

declare(strict_types=1);

namespace App\Services\Trees;

use App\Models\Trees\Tree;
use App\Models\User;
use App\Repositories\Inspections\TreeInspectionRepository;
use App\Validation\Trees\TreeRegistrationInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Inspections\TreeInspectionService;

/** Confirma identidad y primera visita juntas, incluyendo condiciones, derivados e historiales. */
class TreeRegistrationService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeService $trees,
        private TreeInspectionService $inspections,
        private TreeInspectionRepository $inspectionRecords,
    ) {
    }

    public function create(User $actor, array $input): Tree
    {
        return DB::transaction(function () use ($actor, $input): Tree {
            $this->access->authorize($actor, 'trees.create');
            $this->access->authorize($actor, 'inspections.create');
            $data = Validator::make($input, TreeRegistrationInput::rules(), TreeRegistrationInput::messages())->validate();
            $inventoryId = $data['first_inspection_inventory_id'] ?? null;
            if ($inventoryId === null && count($data['inventory_ids']) === 1) {
                $inventoryId = $data['inventory_ids'][0];
            }
            if ($inventoryId === null || !in_array($inventoryId, $data['inventory_ids'], true)) {
                throw ValidationException::withMessages([
                    'first_inspection_inventory_id' => 'Selecciona la campaña de esta primera visita entre los inventarios vinculados al árbol.',
                ]);
            }

            $tree = $this->trees->create($actor, $data);
            $participation = $this->inspectionRecords->inventoryOptionsForTree($tree->organization_id, $tree->id)
                ->firstWhere('inventory_id', $inventoryId);
            if ($participation === null) {
                throw ValidationException::withMessages(['first_inspection_inventory_id' => 'La campaña seleccionada no está vinculada al árbol.']);
            }
            $data['inventory_tree_id'] = $participation->id;
            $this->inspections->create($actor, $tree->id, $data);

            return $tree;
        }, 3);
    }
}
