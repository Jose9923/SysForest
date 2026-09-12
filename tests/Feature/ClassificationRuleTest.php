<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClassificationRuleVersion;
use App\Models\MaturityRuleBand;
use App\Models\MaturityState;
use App\Repositories\ClassificationRuleRepository;
use App\Services\ClassificationService;
use Database\Seeders\ClassificationRuleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class ClassificationRuleTest extends PostgresTestCase
{
    private ClassificationRuleVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'MAT_YOUNG' => 'Fustal joven',
            'MAT_MEDIUM' => 'Fustal medio',
            'MAT_MATURE' => 'Fustal maduro',
            'MAT_OVERMATURE' => 'Fustal sobremaduro',
        ] as $code => $name) {
            MaturityState::query()->firstOrCreate(['code' => $code], ['name' => $name, 'active' => true, 'sort_order' => 0]);
        }
        $this->seed(ClassificationRuleSeeder::class);
        $this->version = app(ClassificationRuleRepository::class)->findByCode('EXCEL_V1');
    }

    public function test_seeder_is_idempotent_and_preserves_administrative_metadata(): void
    {
        $ids = $this->version->maturityBands->pluck('id')->all();
        $sealedAt = $this->version->sealed_at->toISOString();
        app(ClassificationRuleRepository::class)->updateMetadata($this->version, [
            'name' => 'Etiqueta revisada', 'description' => 'Nota administrativa', 'active' => false, 'sort_order' => 8,
        ]);

        $this->seed(ClassificationRuleSeeder::class);
        $version = app(ClassificationRuleRepository::class)->findByCode('EXCEL_V1');

        $this->assertSame($this->version->id, $version->id);
        $this->assertSame($ids, $version->maturityBands->pluck('id')->all());
        $this->assertSame($sealedAt, $version->sealed_at->toISOString());
        $this->assertSame('Etiqueta revisada', $version->name);
        $this->assertSame('Nota administrativa', $version->description);
        $this->assertSame(8, $version->sort_order);
        $this->assertFalse($version->active);
    }

    public function test_service_uses_the_correct_step_unit_and_version(): void
    {
        $service = app(ClassificationService::class);
        $diameter = $service->diameter('10.000001', $this->version->id);
        $height = $service->height('10.000001', $this->version->id);

        $this->assertSame(3, $diameter->classNumber);
        $this->assertSame('cm', $diameter->unit);
        $this->assertSame(2, $height->classNumber);
        $this->assertSame('m', $height->unit);
        $this->assertSame($this->version->id, $height->ruleVersionId);
        $this->assertSame('positive_ceiling_v1', $this->version->algorithm);
    }

    #[DataProvider('maturityBoundaries')]
    public function test_maturity_preserves_the_four_excel_states(int $number, string $code): void
    {
        $state = app(ClassificationService::class)->maturity($number, $this->version->id);
        $this->assertSame($code, $state->code);
    }

    public static function maturityBoundaries(): array
    {
        return [
            [1, 'MAT_YOUNG'], [3, 'MAT_YOUNG'], [4, 'MAT_MEDIUM'], [6, 'MAT_MEDIUM'],
            [7, 'MAT_MATURE'], [9, 'MAT_MATURE'], [10, 'MAT_OVERMATURE'], [999999, 'MAT_OVERMATURE'],
        ];
    }

    public function test_deactivated_versions_and_states_remain_available_for_historical_reproduction(): void
    {
        $repository = app(ClassificationRuleRepository::class);
        $repository->updateMetadata($this->version, ['active' => false]);
        MaturityState::query()->where('code', 'MAT_YOUNG')->update(['active' => false]);

        $this->assertFalse($repository->activeVersions()->contains('id', $this->version->id));
        $this->assertSame(1, app(ClassificationService::class)->diameter('5', $this->version->id)->classNumber);
        $this->assertSame('MAT_YOUNG', app(ClassificationService::class)->maturity(1, $this->version->id)->code);
    }

    public function test_a_new_version_does_not_change_results_from_the_old_version(): void
    {
        $new = $this->draft('TEST_V2', '10');
        foreach ($this->version->maturityBands as $band) {
            $this->band($new, $band->min_class, $band->max_class, $band->maturity_state_id);
        }
        $new->forceFill(['sealed_at' => now()])->save();

        $service = app(ClassificationService::class);
        $this->assertSame(2, $service->diameter('6', $this->version->id)->classNumber);
        $this->assertSame(1, $service->diameter('6', $new->id)->classNumber);
        $this->assertSame($this->version->id, $service->diameter('6', $this->version->id)->ruleVersionId);
    }

    public function test_administrative_repository_rejects_parameter_changes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ClassificationRuleRepository::class)->updateMetadata($this->version, ['diameter_step' => '7']);
    }

    public function test_database_rejects_parameter_changes_even_when_eloquent_is_bypassed(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('classification_rule_versions')->where('id', $this->version->id)->update(['diameter_step' => '7']);
    }

    public function test_database_rejects_replacing_a_version_code(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('classification_rule_versions')->where('id', $this->version->id)->update(['code' => 'REPLACED']);
    }

    public function test_database_rejects_removing_the_seal(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('classification_rule_versions')->where('id', $this->version->id)->update(['sealed_at' => null]);
    }

    public function test_database_rejects_deleting_a_version(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('classification_rule_versions')->where('id', $this->version->id)->delete();
    }

    public function test_database_rejects_modifying_a_maturity_band(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('maturity_rule_bands')->where('id', $this->version->maturityBands->first()->id)->update(['max_class' => 4]);
    }

    public function test_database_rejects_deleting_a_maturity_band(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        DB::table('maturity_rule_bands')->where('id', $this->version->maturityBands->first()->id)->delete();
    }

    public function test_database_rejects_adding_bands_after_sealing(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $this->band($this->version, 20, 25);
    }

    public function test_database_requires_real_maturity_state_references(): void
    {
        $draft = $this->draft('FK_TEST');
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        $this->band($draft, 1, null, (string) Str::uuid());
    }

    public function test_database_rejects_a_gap_when_sealing(): void
    {
        $draft = $this->draft('GAP_TEST');
        $this->band($draft, 1, 3);
        $this->band($draft, 5, null);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $draft->forceFill(['sealed_at' => now()])->save();
    }

    public function test_database_rejects_overlapping_bands_when_sealing(): void
    {
        $draft = $this->draft('OVERLAP_TEST');
        $this->band($draft, 1, 3);
        $this->band($draft, 3, null);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $draft->forceFill(['sealed_at' => now()])->save();
    }

    public function test_database_rejects_a_finite_last_band_when_sealing(): void
    {
        $draft = $this->draft('FINITE_TEST');
        $this->band($draft, 1, 3);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $draft->forceFill(['sealed_at' => now()])->save();
    }

    public function test_database_rejects_creating_an_already_sealed_version(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $this->draft('SEALED_TEST', extra: ['sealed_at' => now()]);
    }

    public function test_database_rejects_duplicate_rule_codes(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        $this->draft('EXCEL_V1');
    }

    public function test_database_rejects_nonpositive_steps(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        $this->draft('ZERO_TEST', '0');
    }

    public function test_database_rejects_negative_order(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(ClassificationRuleRepository::class)->updateMetadata($this->version, ['sort_order' => -1]);
    }

    public function test_an_unsealed_version_cannot_be_used_for_calculation(): void
    {
        $draft = $this->draft('DRAFT_TEST');
        $this->expectException(ModelNotFoundException::class);
        app(ClassificationService::class)->diameter('5', $draft->id);
    }

    public function test_invalid_version_ids_do_not_reach_postgresql_uuid_casting(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(ClassificationService::class)->height('5', 'invalid-uuid');
    }

    public function test_service_returns_a_validation_error_for_nonpositive_measurements(): void
    {
        $this->expectException(ValidationException::class);
        app(ClassificationService::class)->diameter('0', $this->version->id);
    }

    public function test_service_rejects_a_nonpositive_maturity_class(): void
    {
        $this->expectException(ValidationException::class);
        app(ClassificationService::class)->maturity(0, $this->version->id);
    }

    private function draft(string $code, string $diameterStep = '5', array $extra = []): ClassificationRuleVersion
    {
        $version = new ClassificationRuleVersion();
        $version->forceFill(array_merge([
            'code' => $code, 'name' => 'Versión de prueba', 'algorithm' => 'positive_ceiling_v1',
            'diameter_step' => $diameterStep, 'height_step' => '10',
            'diameter_unit' => 'cm', 'height_unit' => 'm', 'active' => true, 'sort_order' => 0,
        ], $extra))->save();

        return $version;
    }

    private function band(
        ClassificationRuleVersion $version,
        int $minimum,
        ?int $maximum,
        ?string $stateId = null,
    ): MaturityRuleBand {
        return MaturityRuleBand::query()->create([
            'rule_version_id' => $version->id,
            'maturity_state_id' => $stateId ?? $this->version->maturityBands->first()->maturity_state_id,
            'min_class' => $minimum, 'max_class' => $maximum,
        ]);
    }
}
