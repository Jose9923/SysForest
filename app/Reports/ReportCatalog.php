<?php

declare(strict_types=1);

namespace App\Reports;

use Illuminate\Validation\ValidationException;

/** Define presentación y permisos; las reglas forestales permanecen en el motor compartido. */
class ReportCatalog
{
    public function definitions(): array
    {
        $trees = ['trees.view'];
        $inspections = ['trees.view', 'inspections.view'];

        return [
            $this->definition('inventory', 'Inventario general', 'Individuos del conjunto filtrado y su última inspección pertinente.', 'árboles', [...$inspections, 'evidences.view']),
            $this->definition('species', 'Especies', 'Individuos por especie, incluyendo los que aún no tienen identificación.', 'árboles', $trees),
            $this->definition('families', 'Familias', 'Individuos por familia taxonómica.', 'árboles', $trees),
            $this->definition('origins', 'Origen', 'Origen de establecimiento y origen biogeográfico en dimensiones independientes.', 'árboles', $trees),
            $this->definition('habits', 'Hábito', 'Individuos por hábito de crecimiento.', 'árboles', $trees),
            $this->definition('states', 'Estado', 'Estado actual de los individuos.', 'árboles', $trees),
            $this->definition('diameter_classes', 'Clases diamétricas', 'Clases de la última inspección, separadas por versión de la regla.', 'árboles', $inspections),
            $this->definition('height_classes', 'Clases de altura', 'Clases de la última inspección, separadas por versión de la regla.', 'árboles', $inspections),
            $this->definition('physical_conditions', 'Estado físico-mecánico', 'Condiciones de la última inspección; un individuo puede tener varias.', 'árboles', $inspections),
            $this->definition('phytosanitary_conditions', 'Estado fitosanitario', 'Condiciones fitosanitarias de la última inspección.', 'árboles', $inspections),
            $this->definition('maintenances', 'Mantenimientos', 'Órdenes no anuladas de los individuos filtrados; públicamente solo ejecutadas o verificadas.', 'mantenimientos', ['trees.view', 'maintenances.view']),
            $this->definition('municipalities', 'Árboles por municipio', 'Individuos por municipio y departamento.', 'árboles', $trees),
            $this->definition('organizations', 'Árboles por organización', 'Individuos por organización.', 'árboles', $trees),
            $this->definition('inspections', 'Resumen de inspecciones', 'Individuos con o sin última inspección pertinente; no cuenta visitas históricas.', 'árboles', $inspections),
            $this->definition('fauna', 'Fauna', 'Categorías observadas en la última inspección, sin textos libres.', 'árboles', $inspections),
            $this->definition('protected', 'Patrimoniales y protegidos', 'Condiciones patrimonial y protegida en dimensiones independientes.', 'árboles', $trees),
            $this->definition(
                'zones',
                'Árboles por zona',
                'Pendiente de una definición institucional de zona.',
                'árboles',
                $trees,
                false,
                'No existe una definición ni un catálogo de zonas. No se sustituye por municipio, emplazamiento o localización libre.'
            ),
        ];
    }

    public function get(string $key): array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        throw ValidationException::withMessages(['report' => 'El reporte solicitado no está disponible.']);
    }

    private function definition(string $key, string $title, string $description, string $unit, array $domains, bool $available = true, ?string $notice = null): array
    {
        return array_filter(compact('key', 'title', 'description', 'unit', 'domains', 'available', 'notice'), fn ($value) => $value !== null);
    }
}
