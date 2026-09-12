<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Forestry\DecimalClassification;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalClassificationTest extends TestCase
{
    #[DataProvider('boundaries')]
    public function test_classifies_exact_positive_decimals(
        string $value,
        string $step,
        int $number,
        string $minimum,
        string $maximum,
    ): void {
        $result = (new DecimalClassification())->classify($value, $step, 'cm', 'version-de-prueba');

        $this->assertSame($number, $result->classNumber);
        $this->assertSame($minimum, $result->minValue);
        $this->assertSame($maximum, $result->maxValue);
        $this->assertSame('version-de-prueba', $result->ruleVersionId);
    }

    public static function boundaries(): array
    {
        return [
            'mínimo decimal admitido' => ['0.000001', '5', 1, '0', '5'],
            'límite DAP 5' => ['5', '5', 1, '0', '5'],
            'DAP después de 5' => ['5.000001', '5', 2, '5', '10'],
            'límite DAP 10' => ['10', '5', 2, '5', '10'],
            'DAP después de 10' => ['10.000001', '5', 3, '10', '15'],
            'límite altura 10' => ['10', '10', 1, '0', '10'],
            'altura después de 10' => ['10.000001', '10', 2, '10', '20'],
            'altura 20' => ['20', '10', 2, '10', '20'],
            'ceros decimales explícitos' => ['5.000000', '5.000000', 1, '0', '5'],
            'sin máximo de clases sembrado' => ['1000000', '5', 200000, '999995', '1000000'],
            'extremo de precisión técnica' => ['999999999999.999999', '5', 200000000000, '999999999995', '1000000000000'],
            'paso decimal exacto' => ['0.3', '0.1', 3, '0.2', '0.3'],
            'paso mínimo exacto' => ['0.000003', '0.000001', 3, '0.000002', '0.000003'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_values_instead_of_rounding_or_coercing_them(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DecimalClassification())->classify($value, '5', 'cm', 'version-de-prueba');
    }

    public static function invalidValues(): array
    {
        return array_map(fn (string $value): array => [$value], [
            '0', '0.000000', '-1', '+5', '5,1', '5e0', 'NaN', 'INF', '',
            ' 5', '5 ', '.5', '5.', '5.0000001', '1000000000000',
        ]);
    }

    public function test_rejects_a_zero_step(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DecimalClassification())->classify('5', '0', 'cm', 'version-de-prueba');
    }

    public function test_serialization_preserves_decimal_strings(): void
    {
        $result = (new DecimalClassification())->classify('5.000001', '5', 'cm', 'version-de-prueba');
        $serialized = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'class_number' => 2,
            'min_value' => '5',
            'max_value' => '10',
            'unit' => 'cm',
            'label' => 'Clase 2 (5 < valor ≤ 10 cm)',
            'rule_version_id' => 'version-de-prueba',
        ], $serialized);
    }
}
