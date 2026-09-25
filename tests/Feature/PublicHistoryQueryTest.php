<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\EvidenceType;
use App\Models\FaunaCategory;
use App\Services\PublicTreeService;
use Illuminate\Support\Facades\DB;
use Tests\PublicPortalTestCase;

/** Mide solo la lectura: los fixtures y sus historiales se construyen antes del contador. */
class PublicHistoryQueryTest extends PublicPortalTestCase
{
    public function test_detail_queries_remain_bounded_with_inspection_maintenance_and_photo_history(): void
    {
        $this->publishTree();
        $condition = Condition::query()->where('assessment_type', 'physical_mechanical')->where('active', true)->firstOrFail();
        $fauna = FaunaCategory::query()->where('active', true)->firstOrFail();
        $types = EvidenceType::query()->where('active', true)->orderBy('code')->limit(3)->pluck('id')->all();
        $counts = [];
        $previous = 0;

        foreach ([1, 16] as $size) {
            for ($index = $previous; $index < $size; $index++) {
                $this->publishInspection($this->capture([
                    'physical_condition_ids' => [$condition->id], 'fauna_category_ids' => [$fauna->id],
                    'general_observations' => 'HISTORY-PRIVATE-OBSERVATION-'.$index,
                ]));
                $this->executedOrder();
                $this->publishEvidence($this->attachEvidence([
                    'evidence_type_id' => $types[$index % count($types)],
                    'notes' => 'HISTORY-PRIVATE-FILE-'.$index,
                ]), 'Imagen forestal '.$index);
            }
            $previous = $size;
            [$detail, $counts[$size]] = $this->measure(fn () => app(PublicTreeService::class)
                ->detail($this->organization->fresh()->public_code, $this->tree->code));
            $this->assertSame($size, $detail['inspectionHistory']->total());
            $this->assertSame($size, $detail['maintenanceHistory']->total());
            $this->assertSame($size, $detail['gallery']->total());
            $this->assertCount(min(15, $size), $detail['inspectionHistory']->items());
            $this->assertCount(min(15, $size), $detail['maintenanceHistory']->items());
            $this->assertCount(min(12, $size), $detail['gallery']->items());
            $this->assertSame([$condition->name], $detail['tree']['latest_inspection']['physical_conditions']);
            $this->assertSame([$fauna->name], $detail['tree']['latest_inspection']['fauna']);
            $public = json_encode([$detail['tree'], $detail['inspectionHistory']->items(),
                $detail['maintenanceHistory']->items(), $detail['gallery']->items()], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('HISTORY-PRIVATE-', $public);
            $this->assertStringNotContainsString($this->admin->email, $public);
            $this->assertStringNotContainsString($this->admin->name, $public);
            $this->assertLessThan(80, $counts[$size], 'Consultas de ficha: '.json_encode($counts));
        }

        $this->assertLessThanOrEqual(
            $counts[1] + 3,
            $counts[16],
            'Una ficha con más observaciones o fotos no debe consultar relaciones por registro: '.json_encode($counts)
        );
    }

    private function measure(callable $operation): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $result = $operation();

            return [$result, count($connection->getQueryLog())];
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }
}
