<?php

declare(strict_types=1);

namespace App\Catalogs;

use App\Models as M;

/** Lista cerrada de catálogos simples: ninguna ruta selecciona tablas arbitrarias. */
class CatalogRegistry
{
    /** @return list<CatalogDefinition> */
    public function all(): array
    {
        return [
            new CatalogDefinition('placements', 'Emplazamientos', 'Localización', 'Lista inicial ampliable de espacios donde se ubica el individuo.', M\Placement::class),
            new CatalogDefinition('establishment-origins', 'Origen de establecimiento', 'Caracterización', 'Forma de establecimiento: espontánea o plantada.', M\EstablishmentOrigin::class),
            new CatalogDefinition('biogeographic-origins', 'Origen biogeográfico', 'Caracterización', 'Clasificación de la procedencia biogeográfica del individuo.', M\BiogeographicOrigin::class),
            new CatalogDefinition('growth-habits', 'Hábitos de crecimiento', 'Caracterización', 'Formas de crecimiento del individuo.', M\GrowthHabit::class),
            new CatalogDefinition('maturity-states', 'Estados de madurez', 'Clasificación', 'Estados Fustal asociados a la clasificación diamétrica.', M\MaturityState::class),
            new CatalogDefinition('evidence-types', 'Tipos de evidencia', 'Gestión forestal', 'Clasificación del contenido de las fotografías.', M\EvidenceType::class),
            new CatalogDefinition('maintenance-types', 'Tipos de mantenimiento', 'Gestión forestal', 'Tipos de intervención y cuidado del individuo.', M\MaintenanceType::class),
            new CatalogDefinition('deletion-reasons', 'Motivos de eliminación', 'Gestión forestal', 'Motivos para anular registros creados por error.', M\DeletionReason::class),
            new CatalogDefinition('fauna-categories', 'Categorías de fauna', 'Gestión forestal', 'Grupos para registrar fauna asociada al individuo.', M\FaunaCategory::class),
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
