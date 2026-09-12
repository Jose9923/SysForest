<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\DendrometryInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DendrometryInputTest extends TestCase
{
    public function test_normalizes_seven_nullable_inputs_and_ignores_client_derivatives(): void
    {
        $this->assertSame([
            'cap_cm' => '154.000000', 'number_of_stems' => 3, 'form_factor' => '0.500000',
            'total_height_m' => null, 'commercial_height_m' => '0.000000', 'crown_x_m' => '0.000000', 'crown_y_m' => null,
        ], DendrometryInput::validate([
            'cap_cm' => 154, 'number_of_stems' => '3', 'form_factor' => '0.5',
            'commercial_height_m' => '0', 'crown_x_m' => 0, 'crown_y_m' => '',
            'dap_m' => '999', 'maturity_state_id' => 'forged', 'calculation_rule_version' => 'FORGED',
        ]));
    }

    public function test_empty_measurements_are_all_null_and_do_not_block_an_inspection(): void
    {
        $this->assertSame(array_fill_keys(DendrometryInput::FIELDS, null), DendrometryInput::validate([]));
    }

    public function test_form_factor_limits_and_technical_maximum_are_accepted_exactly(): void
    {
        foreach (['0.45', '0.7'] as $factor) {
            $result = DendrometryInput::validate(['cap_cm' => '999999999999.999999', 'form_factor' => $factor, 'number_of_stems' => (string) PHP_INT_MAX]);
            $this->assertSame('999999999999.999999', $result['cap_cm']);
            $this->assertSame(PHP_INT_MAX, $result['number_of_stems']);
        }
    }

    public function test_commercial_height_can_be_known_without_total_height(): void
    {
        $result = DendrometryInput::validate(['commercial_height_m' => '0.8']);
        $this->assertSame('0.800000', $result['commercial_height_m']);
        $this->assertNull($result['total_height_m']);
    }

    public function test_commercial_height_comparison_does_not_lose_a_decimal_at_large_values(): void
    {
        $this->expectException(ValidationException::class);
        DendrometryInput::validate(['total_height_m' => '999999999999.999998', 'commercial_height_m' => '999999999999.999999']);
    }

    public function test_equal_heights_are_valid(): void
    {
        $result = DendrometryInput::validate(['total_height_m' => '12.000001', 'commercial_height_m' => '12.000001']);
        $this->assertSame($result['total_height_m'], $result['commercial_height_m']);
    }

    public function test_public_rules_can_be_composed_by_inspection_requests(): void
    {
        $result = Validator::make(['cap_cm' => '154', 'inspection_date' => '2026-09-12'], array_merge(DendrometryInput::rules(), ['inspection_date' => ['required', 'date']]))->validate();
        $this->assertSame('154', $result['cap_cm']);
        $this->assertSame('2026-09-12', $result['inspection_date']);
    }

    #[DataProvider('invalidMeasurements')]
    public function test_rejects_invalid_measures_without_coercion_or_premature_rounding(string $field, mixed $value): void
    {
        try {
            DendrometryInput::validate([$field => $value]);
            $this->fail('Se aceptó una medida inválida.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidMeasurements(): array
    {
        $cases = [];
        foreach (['0', '-1', '+154', '1e2', '154,1', 'NaN', 'INF', ' 154', '154 ', '.5', '5.', '0.0000001', '1000000000000', true, false, [], 154.1] as $value) {
            $cases[] = ['cap_cm', $value];
        }
        foreach (['0', '-1', '3.5', '3.0', '9223372036854775808', '1e2', true] as $value) {
            $cases[] = ['number_of_stems', $value];
        }
        foreach (['0.449999', '0.700001', '1', '-0.5', '0.4500001'] as $value) {
            $cases[] = ['form_factor', $value];
        }
        foreach (['0', '-0.1'] as $value) {
            $cases[] = ['total_height_m', $value];
        }
        foreach (['commercial_height_m', 'crown_x_m', 'crown_y_m'] as $field) {
            $cases[] = [$field, '-0.000001'];
        }

        return $cases;
    }
}
