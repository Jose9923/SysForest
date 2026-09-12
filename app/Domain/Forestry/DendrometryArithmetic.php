<?php

declare(strict_types=1);

namespace App\Domain\Forestry;

/** Fórmulas DENDROMETRY_V1: operandos normalizados por DendrometryInput, sin E/S ni persistencia. */
final class DendrometryArithmetic
{
    public const VERSION = 'DENDROMETRY_V1';
    public const PI = '3.14159265358979323846264338327950288419716939937510';
    public const INTERNAL_SCALE = 40;
    public const STORAGE_SCALE = 12;

    /**
     * No reutiliza resultados redondeados entre fórmulas. El conteo de fustes
     * se conserva como observación y nunca multiplica área ni volumen.
     *
     * @param array<string, string|int|null> $measurements
     * @return array<string, string|null>
     */
    public function calculate(array $measurements): array
    {
        $cap = $measurements['cap_cm'] ?? null;
        $factor = $measurements['form_factor'] ?? null;
        $totalHeight = $measurements['total_height_m'] ?? null;
        $commercialHeight = $measurements['commercial_height_m'] ?? null;
        $dap = $cap === null ? null : bcdiv(bcdiv($cap, '100', self::INTERNAL_SCALE), self::PI, self::INTERNAL_SCALE);
        $radius = $dap === null ? null : bcdiv($dap, '2', self::INTERNAL_SCALE);
        $area = $radius === null ? null : bcmul(self::PI, bcmul($radius, $radius, self::INTERNAL_SCALE), self::INTERNAL_SCALE);

        $results = [
            'dap_m' => $dap,
            'radius_m' => $radius,
            'basal_area_m2' => $area,
            'commercial_volume_m3' => $this->volume($area, $commercialHeight, $factor),
            'total_volume_m3' => $this->volume($area, $totalHeight, $factor),
        ];

        return array_map(fn (?string $value): ?string => $value === null ? null : $this->roundForStorage($value), $results);
    }

    private function volume(?string $area, ?string $height, ?string $factor): ?string
    {
        if ($area === null || $height === null || $factor === null) {
            return null;
        }

        return bcmul(bcmul($area, $height, self::INTERNAL_SCALE), $factor, self::INTERNAL_SCALE);
    }

    /** Todos los resultados son no negativos: half-up mediante suma exacta y truncado final. */
    private function roundForStorage(string $value): string
    {
        return bcadd($value, '0.0000000000005', self::STORAGE_SCALE);
    }
}
