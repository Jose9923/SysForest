<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/** Alcance administrativo explícito; no activa ni amplía un contexto institucional. */
final readonly class AuditScope
{
    /**
     * @param array<string> $departmentIds Departamentos autorizados vigentes.
     * @param array<string> $organizationIds Instituciones del alcance, incluidas las inactivas.
     */
    public function __construct(
        public string $kind,
        public ?string $organizationId = null,
        public ?string $organizationName = null,
        public array $departmentIds = [],
        public array $organizationIds = [],
    ) {
    }

    public function label(): string
    {
        return match ($this->kind) {
            'national' => 'Auditoría nacional',
            'department' => 'Auditoría departamental',
            default => $this->organizationName ?? 'Institución seleccionada',
        };
    }
}
