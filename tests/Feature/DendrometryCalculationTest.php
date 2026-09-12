<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClassificationRuleVersion;
use App\Models\MaturityRuleBand;
use App\Models\MaturityState;
use App\Repositories\ClassificationRuleRepository;
use App\Services\DendrometryCalculationService;
use Database\Seeders\ClassificationRuleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;

class DendrometryCalculationTest extends PostgresTestCase
{
    private ClassificationRuleVersion $version;
    private DendrometryCalculationService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'MAT_YOUNG' => 'Fustal joven', 'MAT_MEDIUM' => 'Fustal medio',
            'MAT_MATURE' => 'Fustal maduro', 'MAT_OVERMATURE' => 'Fustal sobremaduro',
        ] as $code => $name) {
            MaturityState::query()->firstOrCreate(['code' => $code], ['name' => $name, 'active' => true]);
        }
        $this->seed(ClassificationRuleSeeder::class);
        $this->version = app(ClassificationRuleRepository::class)->findByCode('EXCEL_V1');
        $this->calculator = app(DendrometryCalculationService::class);
    }

    public function test_control_example_references_the_real_sealed_version_and_maturity_catalog(): void
    {
        $result = $this->calculator->calculate(['cap_cm' => '154', 'number_of_stems' => 3, 'form_factor' => '0.5', 'total_height_m' => '12', 'commercial_height_m' => '0.8']);

        $this->assertSame($this->version->id, $result['classification_rule_version_id']);
        $this->assertSame('DENDROMETRY_V1', $result['calculation_rule_version']);
        $this->assertSame(10, $result['diameter_class_number']);
        $this->assertSame(2, $result['height_class_number']);
        $this->assertDatabaseHas('maturity_states', ['id' => $result['maturity_state_id'], 'code' => 'MAT_OVERMATURE']);
        $this->assertSame('0.075490372607', $result['commercial_volume_m3']);
        $this->assertSame('1.132355589110', $result['total_volume_m3']);
    }

    public function test_deactivated_rule_and_state_reproduce_the_original_calculation_but_not_new_capture(): void
    {
        $before = $this->calculator->calculate(['cap_cm' => '154']);
        app(ClassificationRuleRepository::class)->updateMetadata($this->version, ['active' => false]);
        MaturityState::query()->whereKey($before['maturity_state_id'])->update(['active' => false]);

        $this->assertSame($before, $this->calculator->calculate(['cap_cm' => '154'], $this->version->id));
        $this->expectException(ValidationException::class);
        $this->calculator->calculate(['cap_cm' => '154']);
    }

    public function test_new_rule_does_not_replace_the_old_rule_for_historical_recalculation(): void
    {
        $new = new ClassificationRuleVersion();
        $new->forceFill([
            'code' => 'DENDROMETRY_TEST_V2', 'name' => 'Regla aislada de prueba',
            'algorithm' => 'positive_ceiling_v1', 'diameter_step' => '10', 'height_step' => '20',
            'diameter_unit' => 'cm', 'height_unit' => 'm', 'active' => true,
        ])->save();
        foreach ($this->version->maturityBands as $band) {
            MaturityRuleBand::query()->create([
                'rule_version_id' => $new->id, 'maturity_state_id' => $band->maturity_state_id,
                'min_class' => $band->min_class, 'max_class' => $band->max_class,
            ]);
        }
        $new->forceFill(['sealed_at' => now()])->save();
        $input = ['cap_cm' => '31.415927', 'total_height_m' => '10.000001'];
        $historical = $this->calculator->calculate($input, $this->version->id);
        $changed = $this->calculator->calculate($input, $new->id);

        $this->assertSame(3, $historical['diameter_class_number']);
        $this->assertSame(2, $changed['diameter_class_number']);
        $this->assertSame(2, $historical['height_class_number']);
        $this->assertSame(1, $changed['height_class_number']);
        $this->assertSame($historical['dap_m'], $changed['dap_m']);
        $this->assertSame($historical, $this->calculator->calculate($input, $this->version->id));
    }

    public function test_declared_numeric_types_preserve_extreme_results_without_overflow(): void
    {
        $result = $this->calculator->calculate([
            'cap_cm' => '999999999999.999999', 'total_height_m' => '999999999999.999999',
            'commercial_height_m' => '0', 'form_factor' => '0.7',
        ]);
        $stored = DB::selectOne(
            'SELECT CAST(? AS numeric(24,12)) AS dap_m, CAST(? AS numeric(24,12)) AS radius_m,
             CAST(? AS numeric(36,12)) AS basal_area_m2, CAST(? AS numeric(48,12)) AS total_volume_m3,
             CAST(? AS bigint) AS diameter_class_number',
            [$result['dap_m'], $result['radius_m'], $result['basal_area_m2'], $result['total_volume_m3'], $result['diameter_class_number']],
        );
        foreach (['dap_m', 'radius_m', 'basal_area_m2', 'total_volume_m3', 'diameter_class_number'] as $key) {
            $this->assertSame($result[$key], $stored->{$key});
        }
        $this->assertGreaterThan(2147483647, $result['diameter_class_number']);
    }
}
