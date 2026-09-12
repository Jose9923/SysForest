<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Forestry\DecimalClassification;
use App\Domain\Forestry\DendrometryArithmetic;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalRatioClassificationTest extends TestCase
{
    #[DataProvider('exactBoundaries')]
    public function test_exact_ratio_boundaries_do_not_round_before_ceiling(string $numerator, string $denominator, int $expected): void
    {
        $result = (new DecimalClassification())->classifyRatio($numerator, $denominator, '5', 'cm', 'v1');
        $this->assertSame($expected, $result->classNumber);
        $this->assertSame('v1', $result->ruleVersionId);
    }

    public static function exactBoundaries(): array
    {
        return [
            ['5.000000', '1', 1], ['5.00000000000000000001', '1', 2],
            ['10', '1', 2], ['10.00000000000000000001', '1', 3],
            ['15', '3', 1], ['15.00000000000000000001', '3', 2],
            ['0.00000000000000000001', '1', 1],
            ['999999999999.999999', '1', 200000000000],
        ];
    }

    public function test_cap_just_above_five_cm_diameter_cannot_be_rounded_down_to_class_one(): void
    {
        $cap = '15.707964';
        $diameterRounded = bcadd(bcdiv($cap, DendrometryArithmetic::PI, 40), '0.0000005', 6);
        $this->assertSame('5.000000', $diameterRounded);
        $this->assertSame(2, (new DecimalClassification())->classifyRatio($cap, DendrometryArithmetic::PI, '5', 'cm', 'v1')->classNumber);
    }

    public function test_cap_just_above_ten_cm_diameter_cannot_be_rounded_down_to_class_two(): void
    {
        $cap = '31.415927';
        $diameterRounded = bcadd(bcdiv($cap, DendrometryArithmetic::PI, 40), '0.0000005', 6);
        $this->assertSame('10.000000', $diameterRounded);
        $this->assertSame(3, (new DecimalClassification())->classifyRatio($cap, DendrometryArithmetic::PI, '5', 'cm', 'v1')->classNumber);
    }

    public function test_exact_internal_pi_multiple_belongs_to_the_inclusive_upper_boundary(): void
    {
        $cap = bcmul(DendrometryArithmetic::PI, '5', 50);
        $this->assertSame(1, (new DecimalClassification())->classifyRatio($cap, DendrometryArithmetic::PI, '5', 'cm', 'v1')->classNumber);
    }

    public function test_existing_public_decimal_contract_still_rejects_more_than_six_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DecimalClassification())->classify('5.0000000001', '5', 'cm', 'v1');
    }

    #[DataProvider('invalidRatios')]
    public function test_invalid_ratio_operands_and_steps_are_rejected(string $numerator, string $denominator, string $step): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DecimalClassification())->classifyRatio($numerator, $denominator, $step, 'cm', 'v1');
    }

    public static function invalidRatios(): array
    {
        return [
            ['0', '1', '5'], ['1', '0', '5'], ['-1', '1', '5'],
            ['1e2', '1', '5'], ['NaN', '1', '5'], ['1', 'INF', '5'],
            ['1', '1', '0'], ['1', '1', '5.0000001'],
        ];
    }
}
