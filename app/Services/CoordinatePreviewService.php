<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CoordinatePreviewService
{
    public function __construct(private ForestryAccessService $access, private CoordinateParserService $parser)
    {
    }

    public function preview(User $actor, string $raw): array
    {
        $this->access->organization($actor);
        if (!$this->access->allows($actor, 'trees.create') && !$this->access->allows($actor, 'trees.update')) {
            throw new AuthorizationException('No tiene permiso para capturar coordenadas.');
        }

        return $this->parser->parse($raw)->toArray();
    }
}
