<?php

declare(strict_types=1);

namespace App\Services\Forestry;

use App\Domain\Forestry\DecimalClassification;
use App\DTOs\Forestry\ClassResult;
use App\Models\Catalogs\ClassificationRuleVersion;
use App\Models\Catalogs\MaturityState;
use App\Repositories\Catalogs\ClassificationRuleRepository;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class ClassificationService
{
    public function __construct(
        private ClassificationRuleRepository $rules,
        private DecimalClassification $decimals,
    ) {
    }

    public function diameter(string $value, string $versionId): ClassResult
    {
        $version = $this->rules->findSealed($versionId);

        return $this->classify($value, $version->diameter_step, $version->diameter_unit, $version);
    }

    /** Los operandos proceden del calculador; la entrada pública diameter conserva escala 6. */
    public function diameterRatio(string $numerator, string $denominator, string $versionId): ClassResult
    {
        $version = $this->rules->findSealed($versionId);
        if ($version->algorithm !== 'positive_ceiling_v1' || $version->diameter_unit !== 'cm') {
            throw new LogicException('La versión no admite la clasificación de diámetro en centímetros.');
        }

        try {
            return $this->decimals->classifyRatio($numerator, $denominator, $version->diameter_step, $version->diameter_unit, $version->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cap_cm' => $exception->getMessage()]);
        }
    }

    public function height(string $value, string $versionId): ClassResult
    {
        $version = $this->rules->findSealed($versionId);

        return $this->classify($value, $version->height_step, $version->height_unit, $version);
    }

    /** Resuelve la banda de una versión; no modifica ni crea inspecciones. */
    public function maturity(int $classNumber, string $versionId): MaturityState
    {
        if ($classNumber < 1) {
            throw ValidationException::withMessages(['class_number' => 'La clase diamétrica debe ser un entero positivo.']);
        }

        $version = $this->rules->findSealed($versionId);
        foreach ($version->maturityBands as $band) {
            if ($classNumber >= $band->min_class && ($band->max_class === null || $classNumber <= $band->max_class)) {
                return $band->maturityState;
            }
        }

        throw new LogicException('La versión sellada no contiene una banda de madurez para esta clase.');
    }

    private function classify(string $value, string $step, string $unit, ClassificationRuleVersion $version): ClassResult
    {
        if ($version->algorithm !== 'positive_ceiling_v1') {
            throw new LogicException('El algoritmo de esta versión no está soportado.');
        }

        try {
            return $this->decimals->classify($value, $step, $unit, $version->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['value' => $exception->getMessage()]);
        }
    }
}
