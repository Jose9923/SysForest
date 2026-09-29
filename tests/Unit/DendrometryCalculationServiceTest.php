<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Forestry\DecimalClassification;
use App\Domain\Forestry\DendrometryArithmetic;
use App\Models\ClassificationRuleVersion;
use App\Models\MaturityRuleBand;
use App\Models\MaturityState;
use App\Repositories\ClassificationRuleRepository;
use App\Services\ClassificationService;
use App\Services\DendrometryCalculationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DendrometryCalculationServiceTest extends TestCase
{
    private const VERSION_ID = '00000000-0000-4000-8000-000000000001';
    private const OTHER_VERSION_ID = '00000000-0000-4000-8000-000000000002';

    public function test_reference_example_has_ten_persistence_columns_and_no_client_derivatives(): void
    {
        $service = $this->service();
        $result = $service->calculate([
            'cap_cm' => '154', 'number_of_stems' => 3, 'form_factor' => '0.5',
            'total_height_m' => '12', 'commercial_height_m' => '0.8',
            'dap_m' => '999', 'radius_m' => '999', 'basal_area_m2' => '999', 'total_volume_m3' => '999',
            'diameter_class_number' => 999, 'height_class_number' => 999,
            'maturity_state_id' => 'forged', 'classification_rule_version_id' => 'forged', 'calculation_rule_version' => 'forged',
        ]);
        $this->assertCount(10, $result);
        $this->assertSame('0.490197224723', $result['dap_m']);
        $this->assertSame('0.188725931518', $result['basal_area_m2']);
        $this->assertSame('1.132355589110', $result['total_volume_m3']);
        $this->assertSame(10, $result['diameter_class_number']);
        $this->assertSame(2, $result['height_class_number']);
        $this->assertSame('maturity-MAT_OVERMATURE', $result['maturity_state_id']);
        $this->assertSame(self::VERSION_ID, $result['classification_rule_version_id']);
        $this->assertSame('DENDROMETRY_V1', $result['calculation_rule_version']);
        $this->assertArrayNotHasKey('cap_cm', $result);
        $this->assertArrayNotHasKey('maturity_name', $result);
    }

    public function test_null_measurements_keep_null_calculations_but_record_rule_version(): void
    {
        $result = $this->service()->calculate([]);
        foreach (['dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3', 'diameter_class_number', 'height_class_number', 'maturity_state_id'] as $field) {
            $this->assertNull($result[$field]);
        }
        $this->assertSame(self::VERSION_ID, $result['classification_rule_version_id']);
    }

    public function test_height_class_does_not_require_cap_and_no_maturity_is_invented(): void
    {
        $result = $this->service()->calculate(['total_height_m' => '10.000001']);
        $this->assertSame(2, $result['height_class_number']);
        $this->assertNull($result['diameter_class_number']);
        $this->assertNull($result['maturity_state_id']);
        $this->assertNull($result['total_volume_m3']);
    }

    #[DataProvider('maturityExamples')]
    public function test_applies_the_four_maturity_bands_from_the_selected_version(string $cap, int $diameterClass, string $state): void
    {
        $result = $this->service()->calculate(['cap_cm' => $cap]);
        $this->assertSame($diameterClass, $result['diameter_class_number']);
        $this->assertSame('maturity-'.$state, $result['maturity_state_id']);
    }

    public static function maturityExamples(): array
    {
        return [
            ['5', 1, 'MAT_YOUNG'], ['45', 3, 'MAT_YOUNG'],
            ['60', 4, 'MAT_MEDIUM'], ['90', 6, 'MAT_MEDIUM'],
            ['100', 7, 'MAT_MATURE'], ['140', 9, 'MAT_MATURE'],
            ['154', 10, 'MAT_OVERMATURE'],
        ];
    }

    public function test_internal_classification_does_not_round_cap_derived_diameter(): void
    {
        $result = $this->service()->calculate(['cap_cm' => '15.707964']);
        $this->assertSame(2, $result['diameter_class_number']);
        $this->assertSame('0.050000002330', $result['dap_m']);
    }

    public function test_default_rule_must_be_active_for_new_captures(): void
    {
        $this->expectException(ValidationException::class);
        $this->service(false)->calculate(['cap_cm' => '154']);
    }

    public function test_explicit_original_version_remains_reproducible_after_deactivation(): void
    {
        $result = $this->service(false, false)->calculate(['cap_cm' => '154'], self::VERSION_ID);
        $this->assertSame(10, $result['diameter_class_number']);
        $this->assertSame('maturity-MAT_OVERMATURE', $result['maturity_state_id']);
        $this->assertSame(self::VERSION_ID, $result['classification_rule_version_id']);
    }

    public function test_new_capture_cannot_assign_an_inactive_maturity_state(): void
    {
        $this->expectException(ValidationException::class);
        $this->service(true, false)->calculate(['cap_cm' => '154']);
    }

    public function test_a_distinct_sealed_version_does_not_change_original_calculations(): void
    {
        $service = $this->service();
        $old = $service->calculate(['cap_cm' => '31.415927'], self::VERSION_ID);
        $new = $service->calculate(['cap_cm' => '31.415927'], self::OTHER_VERSION_ID);
        $this->assertSame(3, $old['diameter_class_number']);
        $this->assertSame(2, $new['diameter_class_number']);
        $this->assertSame($old['dap_m'], $new['dap_m']);
        $this->assertSame(self::VERSION_ID, $old['classification_rule_version_id']);
        $this->assertSame(self::OTHER_VERSION_ID, $new['classification_rule_version_id']);
    }

    public function test_unknown_version_has_a_field_validation_error(): void
    {
        try {
            $this->service()->calculate(['cap_cm' => '154'], 'missing');
            $this->fail('Se calculó con una versión inexistente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('classification_rule_version_id', $exception->errors());
        }
    }

    public function test_invalid_measurement_is_rejected_before_loading_a_rule(): void
    {
        $rules = $this->createMock(ClassificationRuleRepository::class);
        $rules->expects($this->never())->method('findForCapture');
        $classification = new ClassificationService($rules, new DecimalClassification());
        $service = new DendrometryCalculationService(new DendrometryArithmetic(), $classification, $rules);
        $this->expectException(ValidationException::class);
        $service->calculate(['cap_cm' => '0']);
    }

    /** Los repositorios son dobles; ningún test de esta clase accede a PostgreSQL. */
    private function service(bool $active = true, bool $statesActive = true): DendrometryCalculationService
    {
        $version = $this->version(self::VERSION_ID, '5', $active, $statesActive);
        $other = $this->version(self::OTHER_VERSION_ID, '10', true, true);
        $rules = $this->createMock(ClassificationRuleRepository::class);
        $rules->method('findForCapture')->with('EXCEL_V1')->willReturn($version);
        $rules->method('findSealed')->willReturnCallback(fn (string $id) => match ($id) {
            self::VERSION_ID => $version,
            self::OTHER_VERSION_ID => $other,
            default => throw (new ModelNotFoundException())->setModel(ClassificationRuleVersion::class),
        });

        return new DendrometryCalculationService(new DendrometryArithmetic(), new ClassificationService($rules, new DecimalClassification()), $rules);
    }

    private function version(string $id, string $diameterStep, bool $active, bool $statesActive): ClassificationRuleVersion
    {
        $version = new ClassificationRuleVersion();
        $version->forceFill([
            'id' => $id, 'code' => 'EXCEL_V1', 'algorithm' => 'positive_ceiling_v1',
            'diameter_step' => $diameterStep, 'height_step' => '10', 'diameter_unit' => 'cm', 'height_unit' => 'm',
            'active' => $active, 'sealed_at' => now(),
        ]);
        $bands = new Collection();
        foreach ([['MAT_YOUNG', 1, 3], ['MAT_MEDIUM', 4, 6], ['MAT_MATURE', 7, 9], ['MAT_OVERMATURE', 10, null]] as [$code, $min, $max]) {
            $state = new MaturityState();
            $state->forceFill(['id' => 'maturity-'.$code, 'code' => $code, 'name' => $code, 'active' => $statesActive]);
            $band = new MaturityRuleBand();
            $band->forceFill(['min_class' => $min, 'max_class' => $max, 'maturity_state_id' => $state->id]);
            $band->setRelation('maturityState', $state);
            $bands->push($band);
        }
        $version->setRelation('maturityBands', $bands);

        return $version;
    }
}
