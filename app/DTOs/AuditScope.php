<?php

declare(strict_types=1);

namespace App\DTOs;

/** La consulta nacional debe solicitarse expresamente si ya existe una institución seleccionada. */
final readonly class AuditScope
{
    public function __construct(public string $kind, public ?string $organizationId = null, public ?string $organizationName = null)
    {
    }
}
