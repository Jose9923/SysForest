<?php

declare(strict_types=1);

namespace App\Services\Forestry;

use App\Domain\Forestry\DendrometryArithmetic;
use App\Models\Catalogs\ClassificationRuleVersion;
use App\Repositories\Catalogs\ClassificationRuleRepository;
use App\Validation\Forestry\DendrometryInput;
use App\Validation\Forestry\ClinometerInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/** No persiste: el servicio de inspección confirma entradas, resultados e hijas en una sola transacción. */
class DendrometryCalculationService
{
    public function __construct(
        private DendrometryArithmetic $arithmetic,
        private ClassificationService $classification,
        private ClassificationRuleRepository $rules,
    ) {
    }

    /**
     * Retorna derivados y versión; el inclinómetro agrega sus alturas y observaciones para la vista previa.
     * El ID explícito debe proceder de una inspección autorizada; permite reproducir
     * su versión sellada aunque ya no se ofrezca para nuevas capturas.
     */
    public function calculate(array $inputs, ?string $versionId = null): array
    {
        $clinometer = ClinometerInput::resolve($inputs);
        $measurements = DendrometryInput::validate(array_replace($inputs, $clinometer));
        $version = $this->resolveVersion($versionId);
        $diameter = $measurements['cap_cm'] === null ? null
            : $this->classification->diameterRatio($measurements['cap_cm'], DendrometryArithmetic::PI, $version->id);
        $height = $measurements['total_height_m'] === null ? null
            : $this->classification->height($measurements['total_height_m'], $version->id);
        $maturity = $diameter === null ? null : $this->classification->maturity($diameter->classNumber, $version->id);
        if ($versionId === null && $maturity !== null && ! $maturity->active) {
            throw ValidationException::withMessages(['maturity_state_id' => 'El estado de madurez de esta regla está desactivado para nuevas inspecciones.']);
        }

        return array_merge($this->arithmetic->calculate($measurements), [
            'diameter_class_number' => $diameter?->classNumber,
            'height_class_number' => $height?->classNumber,
            'maturity_state_id' => $maturity?->id,
            'classification_rule_version_id' => $version->id,
            'calculation_rule_version' => DendrometryArithmetic::VERSION,
        ], $clinometer['uses_clinometer'] === true ? $clinometer : []);
    }

    private function resolveVersion(?string $versionId): ClassificationRuleVersion
    {
        try {
            $version = $versionId === null ? $this->rules->findForCapture('EXCEL_V1') : $this->rules->findSealed($versionId);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['classification_rule_version_id' => 'No está disponible la versión de clasificación requerida.']);
        }
        if ($version->sealed_at === null || ($versionId === null && ! $version->active)) {
            throw ValidationException::withMessages(['classification_rule_version_id' => 'La captura nueva requiere EXCEL_V1 activa y sellada.']);
        }

        return $version;
    }
}
