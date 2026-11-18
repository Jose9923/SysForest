<?php

declare(strict_types=1);

namespace App\Services\Carbon;

use App\Repositories\Carbon\CarbonRepository;
use App\Repositories\Identifiers\IdentifierRepository;
use App\Repositories\Auth\SecurityEventRepository;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Precarga documentada e independiente por entidad; no firma una validación profesional. */
class CarbonReferenceSeedService
{
    private const PACKAGE_HASH = '943fcb1c8e11e3ff25527da5b6838675597638f252a018541cf70e3f816a90f9';

    public function __construct(private CarbonRepository $repository, private SecurityEventRepository $events, private IdentifierRepository $identifiers)
    {
    }

    public function seed(): array
    {
        $path = database_path('seeders/data/carbon-species-references.json');
        if (!hash_equals(self::PACKAGE_HASH, hash_file('sha256', $path))) {
            throw new LogicException('La huella del paquete de referencias de carbono no coincide.');
        }
        $package = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $references = array_column($package['species'], null, 'species_code');
        $species = $this->repository->seedSpecies();
        $result = ['created' => 0, 'existing' => 0, 'mismatched' => 0];
        foreach ($this->repository->seedOrganizations() as $organization) {
            $counts = DB::transaction(function () use ($organization, $references, $species, $package): array {
                $this->repository->lockOrganization($organization->id);
                $counts = ['created' => 0, 'existing' => 0, 'mismatched' => 0];
                foreach ($species as $item) {
                    $reference = $references[$item->code] ?? null;
                    if ($reference === null || $reference['scientific_name'] !== $item->scientific_name
                        || mb_strtoupper($reference['family']) !== mb_strtoupper($item->family->scientific_name)) {
                        $counts['mismatched']++;
                        continue;
                    }
                    // Un reseed nunca revive una precarga retirada ni reemplaza decisiones institucionales.
                    if ($this->repository->active($organization->id, $item->id) !== null
                        || $this->repository->seedReceipt($organization->id, $item->id, $package['version'])) {
                        $counts['existing']++;
                        continue;
                    }
                    $attributes = array_intersect_key($reference, array_flip(['applicable', 'wood_density',
                        'max_diameter_cm', 'max_height_m', 'reference', 'justification', 'validation_status']));
                    $this->repository->publish($organization->id, null, $attributes + [
                        'species_id' => $item->id, 'source_package' => $package['version'],
                    ]);
                    $counts['created']++;
                }
                if ($counts['created'] > 0) {
                    $this->events->record(
                        'carbon.references.seeded',
                        organizationId: $organization->id,
                        metadata: ['reason' => 'Precarga bibliográfica provisional solicitada; sin aprobación profesional local.'],
                        resourceType: 'carbon_profile',
                        resourceId: $this->identifiers->external('organizations', $organization->id),
                        beforeValues: [],
                        afterValues: ['configuration_json' => json_encode(['source_package' => $package['version'],
                            'package_sha256' => self::PACKAGE_HASH, 'counts' => $counts], JSON_UNESCAPED_UNICODE)]
                    );
                }

                return $counts;
            }, 3);
            foreach ($counts as $key => $value) {
                $result[$key] += $value;
            }
        }

        return $result;
    }
}
