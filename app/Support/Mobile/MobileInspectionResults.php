<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Enums\Risk\RiskPriority;
use App\Models\Inspections\TreeInspection;
use App\Support\Carbon\CarbonPresentation;
use App\Support\Risk\RiskPresentation;

/** Proyección privada y de solo lectura; no expone modelos, usuarios ni configuración institucional completa. */
final class MobileInspectionResults
{
    public static function project(TreeInspection $inspection, bool $canViewRisk): array
    {
        return ['risk' => $canViewRisk ? self::risk($inspection) : null, 'carbon' => self::carbon($inspection)];
    }

    private static function risk(TreeInspection $inspection): array
    {
        $evaluation = $inspection->getRelation('riskEvaluation');
        // Una corrección concurrente entre la lectura de la visita y sus relaciones exige otra consulta.
        if ($evaluation?->inspection_version !== $inspection->lock_version) {
            $evaluation = null;
        }
        $methodology = $evaluation?->getRelation('methodology');
        $result = $evaluation === null ? null : RiskPresentation::result($evaluation->result, $methodology?->configuration ?? []);
        $status = match (true) {
            $evaluation === null => 'unassessed',
            ($result['status'] ?? '') === 'inconsistent' => 'inconsistent',
            $evaluation->priority === null => 'pending_confirmation',
            default => 'confirmed',
        };

        return ['scope' => 'phytosanitary_management_priority', 'status' => $status,
            'label' => RiskPresentation::label($evaluation), 'inspection_version' => $inspection->lock_version,
            'methodology' => $methodology === null ? null : ['id' => $methodology->id, 'version' => $methodology->version,
                'name' => $methodology->name, 'code' => $result['methodology_code']],
            'suggested_priority' => $result['priority'] ?? null, 'suggested_label' => $result['priority_label'] ?? null,
            'confirmed_priority' => $evaluation?->priority,
            'confirmed_label' => $evaluation?->priority === null ? null : RiskPriority::from($evaluation->priority)->label(),
            'confirmed_at' => $evaluation?->confirmed_at?->toISOString(),
            'evaluated_at' => $result['carried_forward']['original_created_at'] ?? $evaluation?->created_at?->toISOString(),
            'warnings' => $result['warnings'] ?? [], 'errors' => $result['errors'] ?? [],
            'coverage_status' => $result['coverage_status'] ?? null,
            'evaluated_condition_count' => $result['evaluated_condition_count'] ?? null,
            'explanation' => $evaluation === null ? null : RiskPresentation::explanation($evaluation),
            'requires_web_confirmation' => $evaluation?->priority === null,
            'requires_confirmation' => $evaluation?->priority === null,
            'supports_mobile_confirmation' => true,
            'notice' => 'Prioridad fitosanitaria; no representa probabilidad de caída. La confirmación profesional se realiza en una app compatible o en la plataforma web.'];
    }

    private static function carbon(TreeInspection $inspection): array
    {
        $estimate = CarbonPresentation::current($inspection);
        $status = $estimate?->status ?? 'unprocessed';
        $profile = $estimate?->method['profile'] ?? null;

        return ['scope' => 'aboveground_stock', 'status' => $status, 'label' => CarbonPresentation::label($status),
            'inspection_version' => $inspection->lock_version, 'method_version' => $estimate?->method_version,
            'biomass_kg' => $estimate?->biomass_kg, 'carbon_kg' => $estimate?->carbon_kg, 'co2_kg' => $estimate?->co2_kg,
            'reference_status' => $profile['validation_status'] ?? null,
            'reference_label' => CarbonPresentation::referenceLabel($profile['validation_status'] ?? null),
            'reference' => $profile['reference'] ?? null,
            'calculated_at' => $estimate?->method['carried_forward']['original_created_at'] ?? $estimate?->created_at?->toISOString(),
            'notice' => 'Estimación de esta visita. El total institucional utiliza la última visita compatible de árboles vivos. No representa huella de emisiones ni captura anual.'];
    }
}
