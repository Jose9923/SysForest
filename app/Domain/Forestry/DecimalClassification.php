<?php

declare(strict_types=1);

namespace App\Domain\Forestry;

use App\DTOs\ClassResult;
use InvalidArgumentException;

final class DecimalClassification
{
    public const SCALE = 6;

    /** Precisión técnica DECIMAL(18,6); no representa un máximo biológico de DAP o altura. */
    public function classify(string $value, string $step, string $unit, string $versionId): ClassResult
    {
        $value = $this->positiveDecimal($value);
        $step = $this->positiveDecimal($step);

        // Los operandos se escalan a enteros decimales antes del cociente y el resto.
        $integerValue = bcmul($value, '1000000', 0);
        $integerStep = bcmul($step, '1000000', 0);
        $quotient = bcdiv($integerValue, $integerStep, 0);
        $remainder = bcmod($integerValue, $integerStep, 0);
        $number = bcadd($quotient, $remainder === '0' ? '0' : '1', 0);

        if (bccomp($number, (string) PHP_INT_MAX, 0) > 0) {
            throw new InvalidArgumentException('La clase supera la precisión entera admitida.');
        }

        $minimum = $this->compact(bcmul(bcsub($number, '1', 0), $step, self::SCALE));
        $maximum = $this->compact(bcmul($number, $step, self::SCALE));

        return new ClassResult(
            (int) $number,
            $minimum,
            $maximum,
            $unit,
            'Clase '.$number.' ('.$minimum.' < valor ≤ '.$maximum.' '.$unit.')',
            $versionId,
        );
    }

    private function positiveDecimal(string $value): string
    {
        if (! preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/', $value)
            || bccomp($value, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Usa un decimal positivo, con punto, hasta 12 enteros y 6 decimales; no se redondea la entrada.');
        }

        return bcadd($value, '0', self::SCALE);
    }

    private function compact(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }
}
