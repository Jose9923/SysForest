<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Evidence;
use App\Models\EvidenceType;
use App\Models\User;
use App\Services\EvidenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class EvidenceTestCase extends InspectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\EvidenceTypeSeeder::class);
        Storage::fake('evidences');
        config(['evidence.disk' => 'evidences']);
    }

    protected function evidenceInput(array $overrides = []): array
    {
        return ['items' => [array_replace(['file' => UploadedFile::fake()->image('individuo.jpg', 48, 32),
            'evidence_type_id' => $this->evidenceType('EVD_WHOLE_TREE')->id, 'caption' => 'Individuo completo'], $overrides)]];
    }

    protected function evidenceType(string $code): EvidenceType
    {
        return EvidenceType::query()->where('code', $code)->sole();
    }

    protected function attachEvidence(array $overrides = [], ?User $actor = null, ?string $inspectionId = null): Evidence
    {
        return app(EvidenceService::class)->upload(
            $actor ?? $this->admin,
            $inspectionId === null ? $this->tree->id : null,
            $inspectionId,
            $this->evidenceInput($overrides)
        )->sole();
    }
}
