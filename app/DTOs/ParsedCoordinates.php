<?php

declare(strict_types=1);

namespace App\DTOs;

use JsonSerializable;

/** Coordenadas normalizadas; raw conserva exactamente el texto suministrado. */
final readonly class ParsedCoordinates implements JsonSerializable
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $raw,
        public string $format,
    ) {
    }

    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'raw' => $this->raw,
            'format' => $this->format,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
