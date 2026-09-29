<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\MapTreeFilterDTO;
use App\DTOs\ReportSelectionDTO;
use App\DTOs\TreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Models\Condition;
use App\Models\FaunaCategory;
use App\Models\Species;
use App\Reports\InventoryExportSchema;
use App\Repositories\ExportReadRepository;
use Illuminate\Support\Facades\DB;
use Tests\PublicPortalTestCase;

/** Proyecciones reales, sin generar archivos ni modificar registros operativos. */
class ExportProjectionTest extends PublicPortalTestCase
{
    public function test_private_schema_covers_every_original_column_and_sixty_five_distinct_conditions(): void
    {
        $schema = app(InventoryExportSchema::class);
        $columns = $schema->columns(false);
        $this->assertCount(count(array_unique(array_column($columns, 'key'))), $columns);
        $this->assertCount(65, InventoryExportSchema::ORIGINAL_CONDITIONS);
        $sources = array_unique(array_map(fn (string $source) => preg_replace('/[0-9]+$/', '', $source), array_column($columns, 'source')));
        $this->assertCount(94, $sources);
        $this->assertContains('Hoja1!A', $sources);
        $this->assertContains('Hoja1!CP', $sources);
        $conditions = Condition::query()->whereIn('code', array_keys(InventoryExportSchema::ORIGINAL_CONDITIONS))->get();
        $this->capture(['physical_condition_ids' => $conditions->where('assessment_type', 'physical_mechanical')->modelKeys(),
            'phytosanitary_condition_ids' => $conditions->where('assessment_type', 'phytosanitary')->modelKeys()]);
        $row = $this->row();
        foreach (InventoryExportSchema::ORIGINAL_CONDITIONS as $code => $definition) {
            $this->assertSame('x', $row['condition_'.$code], $code);
        }
        $this->assertSame(array_column($columns, 'key'), array_keys(array_filter($row, fn (string $key) => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY)));
    }

    public function test_empty_visit_and_geometry_remain_null_while_boolean_false_and_legacy_zero_survive(): void
    {
        $this->tree->forceFill(['external_number' => '0', 'is_protected' => false, 'is_heritage' => false])->save();
        $row = $this->row();
        $this->assertSame('0', $row['external_number']);
        $this->assertSame('active', $row['current_status']);
        $this->assertSame('Activo', $row['current_status_label']);
        $this->assertFalse($row['is_protected']);
        $this->assertFalse($row['is_heritage']);
        foreach (['cap_cm', 'dap_m', 'number_of_stems', 'latest_inspection_date', 'latitude', 'longitude',
            'condition_PHY_ROOT_GOOD_ANCHORAGE', 'fauna', 'tree_photo_urls', '_geometry'] as $key) {
            $this->assertNull($row[$key], $key);
        }
    }

    public function test_persisted_decimal_scale_zero_and_exact_private_text_are_not_recomputed_or_trimmed(): void
    {
        $text = "  Floración observada; =texto\nSegunda línea  ";
        $inspection = $this->capture(['cap_cm' => '123456789.123456', 'total_height_m' => '12',
            'commercial_height_m' => '0', 'flowering_date' => '2026-09-10', 'flowering_development' => $text,
            'fruiting_development' => '0', 'fauna_observations' => $text, 'general_observations' => $text]);
        $row = $this->row();
        foreach (['cap_cm', 'dap_m', 'radius_m', 'basal_area_m2', 'total_volume_m3', 'commercial_volume_m3'] as $key) {
            $this->assertSame($inspection->getAttribute($key), $row[$key], $key);
            $this->assertIsString($row[$key]);
        }
        $this->assertSame('0.000000', $row['commercial_height_m']);
        $this->assertSame('0.000000000000', $row['commercial_volume_m3']);
        $this->assertSame($text, $row['flowering_development']);
        $this->assertSame($text, $row['fauna_observations']);
        $this->assertSame($text, $row['general_observations']);
        $this->assertSame('0', $row['fruiting_development']);
        $this->assertSame('2026-09-10', $row['flowering_date']);
        $this->assertSame('EXCEL_V1', $row['classification_rule_version']);
        $this->assertSame('DENDROMETRY_V1', $row['calculation_rule_version']);
    }

    public function test_complementary_conditions_keep_code_organ_and_label_without_losing_inactive_history(): void
    {
        $original = $this->condition('PHY_ROOT_BAD_ANCHORAGE');
        $extra = Condition::query()->create(['code' => 'PHY_ROOT_EXPORT_EXTRA', 'name' => 'Observación complementaria de prueba',
            'assessment_type' => 'physical_mechanical', 'condition_category_id' => $original->condition_category_id,
            'active' => true, 'sort_order' => 999]);
        $fauna = FaunaCategory::query()->firstOrFail();
        $this->capture(['physical_condition_ids' => [$original->id, $extra->id], 'fauna_category_ids' => [$fauna->id]]);
        $extra->update(['active' => false]);
        $original->update(['active' => false]);
        $fauna->update(['active' => false]);
        $row = $this->row();
        $this->assertSame('x', $row['condition_PHY_ROOT_BAD_ANCHORAGE']);
        $this->assertStringContainsString('PHY_ROOT_EXPORT_EXTRA / Sistema radicular / Observación complementaria de prueba', $row['physical_additional_conditions']);
        $this->assertNull($row['phytosanitary_additional_conditions']);
        $this->assertSame($fauna->name, $row['fauna']);
    }

    public function test_taxonomy_org_and_spatial_values_are_projected_without_user_or_uuid_columns(): void
    {
        $species = Species::query()->where('code', 'SPECIES_LICANIA_TOMENTOSA')->sole();
        $this->tree->forceFill(['species_id' => $species->id, 'coordinate_raw' => '0, 0', 'datum' => null])->save();
        DB::update('UPDATE trees SET geometry = ST_SetSRID(ST_MakePoint(0, 0), 4326) WHERE id = ?', [$this->tree->id]);
        $row = $this->row();
        $this->assertSame('0.0', $row['latitude']);
        $this->assertSame('0.0', $row['longitude']);
        $this->assertSame(['type' => 'Point', 'coordinates' => [0.0, 0.0]], $row['_geometry']);
        $this->assertSame('Oití', $row['common_name']);
        $this->assertSame($species->scientific_name, $row['scientific_name']);
        $this->assertSame($this->organization->name, $row['organization']);
        $this->assertSame($this->organization->code, $row['organization_code']);
        $this->assertSame($this->tree->id, $row['_source']['tree_id']);
        foreach (['id', 'tree_id', 'organization_id', 'created_by', 'updated_by', 'creator', 'updater', 'geometry'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
        foreach (array_diff_key($row, array_flip(['_geometry', '_source'])) as $value) {
            $this->assertTrue(is_scalar($value) || $value === null);
        }
    }

    public function test_private_photos_are_only_cn_co_of_selected_visit_and_preserve_multiple_urls(): void
    {
        $old = $this->capture(['inspection_date' => '2025-01-01']);
        $oldPhoto = $this->attachEvidence(inspectionId: $old->id);
        $treePhoto = $this->attachEvidence();
        $latest = $this->capture();
        $one = $this->attachEvidence(inspectionId: $latest->id);
        $two = $this->attachEvidence(inspectionId: $latest->id);
        $fauna = $this->attachEvidence(['evidence_type_id' => $this->evidenceType('EVD_FAUNA')->id], inspectionId: $latest->id);
        $row = $this->row();
        $this->assertCount(2, explode("\n", $row['tree_photo_urls']));
        foreach ([$one, $two] as $photo) {
            $this->assertStringContainsString(route('evidences.view', ['evidence' => $photo->id]), $row['tree_photo_urls']);
        }
        $this->assertSame(route('evidences.view', ['evidence' => $fauna->id]), $row['fauna_photo_urls']);
        foreach ([$treePhoto, $oldPhoto] as $excluded) {
            $this->assertStringNotContainsString($excluded->id, $row['tree_photo_urls']);
        }
        $this->assertEqualsCanonicalizing([$one->id, $two->id, $fauna->id], $row['_source']['evidence_ids']);
    }

    public function test_public_projection_excludes_all_private_fields_and_original_urls_even_when_populated(): void
    {
        $this->tree->forceFill(['external_number' => 'LEGADO PRIVADO', 'location_description' => 'UBICACIÓN PRIVADA',
            'coordinate_raw' => '0, 0', 'gps_brand' => 'GPS PRIVADO'])->save();
        $this->publishTree();
        $inspection = $this->publishInspection($this->capture(['general_observations' => 'OBSERVACIÓN PRIVADA',
            'flowering_development' => 'DESARROLLO PRIVADO', 'fauna_observations' => 'FAUNA PRIVADA']));
        $photo = $this->publishEvidence($this->attachEvidence(inspectionId: $inspection->id));
        $privatePhoto = $this->attachEvidence(inspectionId: $inspection->id);
        $row = $this->row(true);
        $schemaKeys = array_column(app(InventoryExportSchema::class)->columns(true), 'key');
        foreach (['external_number', 'location_description', 'coordinate_raw', 'datum', 'accuracy_m', 'gps_brand', 'gps_model',
            'location_captured_at', 'flowering_development', 'fruiting_development', 'fauna_observations', 'general_observations',
            'classification_rule_version', 'calculation_rule_version', 'disk', 'path', 'original_name', 'checksum_sha256'] as $key) {
            $this->assertArrayNotHasKey($key, $row, $key);
            $this->assertNotContains($key, $schemaKeys);
        }
        $this->assertSame(route('public.evidences.file', ['evidence' => $photo->public_id]), $row['tree_photo_urls']);
        $download = json_encode(array_intersect_key($row, array_flip($schemaKeys)), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVAD', $download);
        $this->assertStringNotContainsString($photo->id, $download);
        $this->assertStringNotContainsString($privatePhoto->id, $download);
        $this->assertStringNotContainsString($photo->path, $download);
        $this->assertSame($this->organization->public_code, $row['organization_code']);
    }

    public function test_hidden_or_voided_photos_do_not_leave_public_links_or_sources(): void
    {
        $this->publishTree();
        $inspection = $this->publishInspection($this->capture());
        $hidden = $this->attachEvidence(inspectionId: $inspection->id);
        $voided = $this->publishEvidence($this->attachEvidence(inspectionId: $inspection->id));
        app(\App\Services\EvidenceService::class)->void($this->admin, $voided->id, [
            'lock_version' => $voided->lock_version, 'deletion_notes' => 'Anulación durante prueba de exportación',
        ]);
        $row = $this->row(true);
        $this->assertNull($row['tree_photo_urls']);
        $this->assertSame([], $row['_source']['evidence_ids']);
        $this->assertNotContains($hidden->id, $row['_source']['evidence_ids']);
    }

    private function row(bool $public = false): array
    {
        $selection = new ReportSelectionDTO(
            $public ? TreeReadScope::published() : TreeReadScope::organization($this->organization->id),
            new MapTreeFilterDTO(TreeFilterDTO::fromValidated(['tree_id' => $this->tree->id]), null),
            []
        );

        return collect(app(ExportReadRepository::class)->rows($selection))->sole();
    }
}
