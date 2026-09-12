<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Forestry\DendrometryArithmetic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DendrometryArithmeticTest extends TestCase
{
    /** Referencias calculadas independientemente con Decimal(100) y área=CAP²/(40000×PI). */
    #[DataProvider('references')]
    public function test_formulas_match_high_precision_independent_references(array $input, array $expected): void
    {
        $actual = (new DendrometryArithmetic())->calculate($input);
        $this->assertSame($expected, array_values($actual));
        foreach ($actual as $value) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{12}$/', $value);
        }
    }

    public static function references(): array
    {
        return [
            'ejemplo Excel' => [
                ['cap_cm' => '154', 'form_factor' => '0.5', 'total_height_m' => '12', 'commercial_height_m' => '0.8'],
                ['0.490197224723', '0.245098612362', '0.188725931518', '0.075490372607', '1.132355589110'],
            ],
            'entradas decimales' => [
                ['cap_cm' => '156.789', 'form_factor' => '0.654321', 'total_height_m' => '12.345678', 'commercial_height_m' => '1.234567'],
                ['0.499074887449', '0.249537443724', '0.195623631320', '0.158025378554', '1.580254809546'],
            ],
            'no reutilizar área normalizada a cero' => [
                ['cap_cm' => '0.000001', 'form_factor' => '0.5', 'total_height_m' => '999999999999.999999', 'commercial_height_m' => '0'],
                ['0.000000003183', '0.000000001592', '0.000000000000', '0.000000000000', '0.000003978874'],
            ],
            'límites técnicos sin máximo biológico' => [
                ['cap_cm' => '999999999999.999999', 'form_factor' => '0.7', 'total_height_m' => '999999999999.999999', 'commercial_height_m' => '0'],
                ['3183098861.837906712195', '1591549430.918953356097', '7957747154594766772.528693859436', '0.000000000000', '5570423008216336735199662693388.992432184561'],
            ],
        ];
    }

    public function test_missing_measurements_do_not_become_zero(): void
    {
        $this->assertSame([
            'dap_m' => null, 'radius_m' => null, 'basal_area_m2' => null,
            'commercial_volume_m3' => null, 'total_volume_m3' => null,
        ], (new DendrometryArithmetic())->calculate([]));
    }

    public function test_each_volume_requires_only_its_own_known_dependencies(): void
    {
        $arithmetic = new DendrometryArithmetic();
        $partial = $arithmetic->calculate(['cap_cm' => '154', 'form_factor' => '0.5', 'commercial_height_m' => '0']);
        $this->assertSame('0.000000000000', $partial['commercial_volume_m3']);
        $this->assertNull($partial['total_volume_m3']);
        $withoutFactor = $arithmetic->calculate(['cap_cm' => '154', 'total_height_m' => '12', 'commercial_height_m' => '0.8']);
        $this->assertNotNull($withoutFactor['basal_area_m2']);
        $this->assertNull($withoutFactor['commercial_volume_m3']);
        $this->assertNull($withoutFactor['total_volume_m3']);
    }

    public function test_stem_count_does_not_multiply_the_example_area_or_volumes(): void
    {
        $arithmetic = new DendrometryArithmetic();
        $input = ['cap_cm' => '154', 'form_factor' => '0.5', 'total_height_m' => '12', 'commercial_height_m' => '0.8'];
        $this->assertSame($arithmetic->calculate($input + ['number_of_stems' => 1]), $arithmetic->calculate($input + ['number_of_stems' => 3]));
    }

    public function test_rounded_dap_is_never_reused_for_area(): void
    {
        $result = (new DendrometryArithmetic())->calculate(['cap_cm' => '154']);
        $this->assertSame('0.188725931518', $result['basal_area_m2']);
        $premature = (string) round(M_PI * (0.490197 ** 2) / 4, 12);
        $this->assertNotSame($premature, $result['basal_area_m2']);
        $this->assertEqualsWithDelta(0.18872593151837, (float) $result['basal_area_m2'], 0.000000000001);
    }
}
