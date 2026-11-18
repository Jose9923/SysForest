<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Identifiers\IdentifierSchema;
use Illuminate\Support\Str;

/** PK real autoincremental y alias externo estable para contratos ya publicados. */
trait HasNumericIdentifiers
{
    public function initializeHasNumericIdentifiers(): void
    {
        $this->setIncrementing(true);
        $this->setKeyType('string');
        $casts = [$this->getKeyName() => 'string'];
        foreach (IdentifierSchema::REFERENCES[$this->getTable()] ?? [] as $column => $target) {
            $casts[$column] = 'string';
        }
        foreach (IdentifierSchema::POLYMORPHIC_REFERENCES[$this->getTable()] ?? [] as $column => $target) {
            $casts[$column] = 'string';
        }
        $this->mergeCasts($casts);
        $this->makeHidden('external_id');
    }

    public static function bootHasNumericIdentifiers(): void
    {
        static::creating(function ($model): void {
            if ($model->getAttribute('external_id') === null) {
                $model->setAttribute('external_id', (string) Str::uuid7());
            }
        });
    }
}
