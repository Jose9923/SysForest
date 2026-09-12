<?php

declare(strict_types=1);

namespace App\DTOs;

use JsonSerializable;

final readonly class ClassResult implements JsonSerializable
{
    public function __construct(
        public int $classNumber,
        public string $minValue,
        public string $maxValue,
        public string $unit,
        public string $label,
        public string $ruleVersionId,
    ) {
    }

    /** Los límites siguen siendo cadenas decimales, también al serializar a JSON. */
    public function toArray(): array
    {
        return [
            'class_number' => $this->classNumber,
            'min_value' => $this->minValue,
            'max_value' => $this->maxValue,
            'unit' => $this->unit,
            'label' => $this->label,
            'rule_version_id' => $this->ruleVersionId,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
