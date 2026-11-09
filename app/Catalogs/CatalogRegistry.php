<?php

declare(strict_types=1);

namespace App\Catalogs;

use App\Models\Catalogs\BiogeographicOrigin;
use App\Models\Catalogs\DeletionReason;
use App\Models\Catalogs\EstablishmentOrigin;
use App\Models\Catalogs\EvidenceType;
use App\Models\Catalogs\FaunaCategory;
use App\Models\Catalogs\GrowthHabit;
use App\Models\Catalogs\MaintenanceType;
use App\Models\Catalogs\LocationChangeType;
use App\Models\Catalogs\MaturityState;
use App\Models\Catalogs\Placement;

/** Lista cerrada de catálogos simples: ninguna ruta selecciona tablas arbitrarias. */
class CatalogRegistry
{
    /** @return list<CatalogDefinition> */
    public function all(): array
    {
        return [
            new CatalogDefinition('location-change-types', 'Tipos de cambio de ubicación', 'Localización', 'Motivos de cambio de ubicación o datos de captura; no modifican el estado del individuo.', LocationChangeType::class),
            new CatalogDefinition('placements', 'Emplazamientos', 'Localización', 'Lista inicial ampliable de espacios donde se ubica el individuo.', Placement::class),
            new CatalogDefinition('establishment-origins', 'Origen de establecimiento', 'Caracterización', 'Forma de establecimiento: espontánea o plantada.', EstablishmentOrigin::class),
            new CatalogDefinition('biogeographic-origins', 'Origen biogeográfico', 'Caracterización', 'Clasificación de la procedencia biogeográfica del individuo.', BiogeographicOrigin::class),
            new CatalogDefinition('growth-habits', 'Hábitos de crecimiento', 'Caracterización', 'Formas de crecimiento del individuo.', GrowthHabit::class),
            new CatalogDefinition('maturity-states', 'Estados de madurez', 'Clasificación', 'Estados Fustal asociados a la clasificación diamétrica.', MaturityState::class),
            new CatalogDefinition('evidence-types', 'Tipos de evidencia', 'Gestión forestal', 'Clasificación del contenido de las fotografías.', EvidenceType::class),
            new CatalogDefinition('maintenance-types', 'Tipos de mantenimiento', 'Gestión forestal', 'Tipos de intervención y cuidado del individuo.', MaintenanceType::class),
            new CatalogDefinition('deletion-reasons', 'Motivos de eliminación', 'Gestión forestal', 'Motivos para anular registros creados por error.', DeletionReason::class),
            new CatalogDefinition('fauna-categories', 'Categorías de fauna', 'Gestión forestal', 'Grupos para registrar fauna asociada al individuo.', FaunaCategory::class),
        ];
    }

    public function find(string $key): CatalogDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }
        abort(404);
    }
}
