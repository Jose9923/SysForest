<?php

declare(strict_types=1);

namespace App\Services\Forestry;

use App\Models\User;
use App\Repositories\Inspections\InspectionReferenceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Inspections\TreeInspectionReadService;

class DendrometryPreviewService
{
    public function __construct(
        private ForestryAccessService $access,
        private TreeInspectionReadService $reader,
        private DendrometryCalculationService $calculations,
        private InspectionReferenceRepository $references,
    ) {
    }

    public function preview(User $actor, array $input): array
    {
        $this->access->organization($actor);
        if (!$this->access->allows($actor, 'inspections.create') && !$this->access->allows($actor, 'inspections.update')) {
            throw new AuthorizationException('No tiene autorización para previsualizar una captura de inspección.');
        }
        $version = null;
        if (!empty($input['inspection_id'])) {
            $version = $this->reader->authorizeExisting($actor, (string) $input['inspection_id'], 'update')->classification_rule_version_id;
        }
        $result = $this->calculations->calculate($input, $version);

        return $result + ['maturity_name' => $this->references->maturityName($result['maturity_state_id'])];
    }
}
