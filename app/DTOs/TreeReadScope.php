<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Describe un alcance de consulta; nunca sustituye la autorización del actor en el Service. */
final readonly class TreeReadScope
{
    private function __construct(
        public string $kind,
        public ?string $organizationId,
        public array $departmentIds,
    ) {
    }

    public static function organization(string $organizationId): self
    {
        self::requireUuid($organizationId);

        return new self('organization', strtolower($organizationId), []);
    }

    /** @param array<string> $departmentIds */
    public static function department(array $departmentIds): self
    {
        if ($departmentIds === []) {
            throw new InvalidArgumentException('El alcance departamental requiere departamentos explícitos.');
        }

        foreach ($departmentIds as $departmentId) {
            if (! is_string($departmentId)) {
                throw new InvalidArgumentException('El departamento debe ser un UUID.');
            }
            self::requireUuid($departmentId);
        }

        $departmentIds = array_values(array_unique(array_map(strtolower(...), $departmentIds)));
        sort($departmentIds, SORT_STRING);

        return new self('department', null, $departmentIds);
    }

    public static function national(): self
    {
        return new self('national', null, []);
    }

    private static function requireUuid(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('El alcance requiere identificadores UUID válidos.');
        }
    }
}
