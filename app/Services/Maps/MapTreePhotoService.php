<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\DTOs\Trees\TreeReadScope;
use App\Models\Evidences\Evidence;
use App\Models\Trees\Tree;
use App\Models\User;
use App\Repositories\Evidences\EvidenceRepository;
use App\Repositories\Maps\MapScopeRepository;
use App\Services\Evidences\EvidenceStorageService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Lectura puntual del mapa amplio: no activa una organización ni modifica la sesión. */
class MapTreePhotoService
{
    public function __construct(
        private MapAccessService $access,
        private MapScopeRepository $scopes,
        private EvidenceRepository $evidences,
        private EvidenceStorageService $storage,
    ) {
    }

    public function metadata(User $actor, string $treeId, array $input = []): array
    {
        [$scope, $tree] = $this->selection($actor, $treeId, $input);
        if (! $this->access->allows($actor, $scope, 'evidences.view')) {
            return ['data' => null, 'meta' => ['can_view_photo' => false]];
        }

        $photo = $this->photo($actor, $scope, $tree);

        return ['data' => $photo === null ? null : [
            'id' => $photo->id,
            'url' => route('maps.tree-photo.content', ['tree' => $tree->id, 'evidence' => $photo->id, 'scope' => $scope->kind], false),
            'caption' => $photo->caption,
            'captured_at' => $photo->captured_at?->toIso8601String(),
        ], 'meta' => ['can_view_photo' => true]];
    }

    /** Reautoriza y reselecciona antes de abrir bytes; una portada sustituida deja de servirse por esta ruta. */
    public function content(User $actor, string $treeId, string $evidenceId, array $input = []): StreamedResponse
    {
        [$scope, $tree] = $this->selection($actor, $treeId, $input);
        $this->access->authorize($actor, $scope, 'evidences.view');
        $photo = $this->photo($actor, $scope, $tree);
        abort_unless($photo !== null && $photo->id === strtolower($evidenceId), 404);
        $extension = match ($photo->mime_type) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null,
        };
        abort_if($extension === null, 404);

        try {
            $stream = $this->storage->read($photo);
        } catch (Throwable) {
            throw new HttpException(404, 'La fotografía de árbol completo no está disponible.');
        }

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $photo->mime_type, 'Content-Length' => (string) $photo->size_bytes,
            'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline; filename="'.$photo->id.'.'.$extension.'"',
        ]);
    }

    /** @return array{TreeReadScope, Tree} */
    private function selection(User $actor, string $treeId, array $input): array
    {
        $validated = Validator::make($input, [
            'scope' => ['nullable', 'string', Rule::in(['organization', 'department', 'national'])],
        ])->validate();
        $scope = $this->access->resolve($actor, $validated['scope'] ?? null);
        $this->access->authorize($actor, $scope, 'trees.view');

        return [$scope, $this->scopes->findTree($scope, $treeId)];
    }

    private function photo(User $actor, TreeReadScope $scope, Tree $tree): ?Evidence
    {
        return $this->evidences->wholeTreePhoto(
            $tree->organization_id,
            $tree->id,
            $this->access->allows($actor, $scope, 'inspections.view')
        );
    }
}
