<?php

declare(strict_types=1);

namespace App\Repositories\Identifiers;

use App\Support\Identifiers\IdentifierSchema;
use App\Support\Identifiers\RecordIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Traduce identidad técnica; cada consulta de negocio conserva su autorización y tenant. */
class IdentifierRepository
{
    private array $byInternal = [];
    private array $byExternal = [];

    public function publicId(Model $model): string|int
    {
        if (!in_array($model->getTable(), IdentifierSchema::TABLES, true)) {
            return $model->getKey();
        }
        $external = $model->getAttribute('external_id');

        return is_string($external) && Str::isUuid($external)
            ? $external : $this->external($model->getTable(), $model->getKey());
    }

    /** @param class-string<Model> $modelClass */
    public function resolveId(string $modelClass, string|int $externalId): string|int
    {
        $model = new $modelClass();
        if (!in_array($model->getTable(), IdentifierSchema::TABLES, true)) {
            if (!RecordIdentifier::isValid($externalId)) {
                throw (new ModelNotFoundException())->setModel($modelClass);
            }

            return $model->getKeyType() === 'int' ? (int) $externalId : (string) $externalId;
        }

        return $this->internal($model->getTable(), $externalId);
    }

    public function internal(string $table, string|int $externalId): string
    {
        $this->assertTable($table);
        if (!is_string($externalId) || !Str::isUuid($externalId)) {
            throw (new ModelNotFoundException())->setModel($table);
        }
        $externalId = strtolower($externalId);
        $this->resolveMany($table, [$externalId]);
        if (!isset($this->byExternal[$table][$externalId])) {
            throw (new ModelNotFoundException())->setModel($table);
        }

        return $this->byExternal[$table][$externalId];
    }

    public function external(string $table, string|int $internalId): string
    {
        $this->assertTable($table);
        if (!RecordIdentifier::isValid($internalId)) {
            throw (new ModelNotFoundException())->setModel($table);
        }
        $this->prime($table, [$internalId]);
        if (!isset($this->byInternal[$table][(string) $internalId])) {
            throw (new ModelNotFoundException())->setModel($table);
        }

        return $this->byInternal[$table][(string) $internalId];
    }

    public function prime(string $table, array $internalIds): void
    {
        $this->assertTable($table);
        $missing = [];
        foreach ($internalIds as $id) {
            if (!RecordIdentifier::isValid($id)) {
                throw new InvalidArgumentException('La referencia interna no es un BIGINT positivo.');
            }
            if (!isset($this->byInternal[$table][(string) $id])) {
                $missing[(string) $id] = (string) $id;
            }
        }
        foreach (array_chunk(array_values($missing), 1000) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->get(['id', 'external_id']) as $row) {
                $this->remember($table, $row);
            }
        }
    }

    /** @return array<string, string> UUID externo => PK interna. Ausentes no se inventan. */
    public function resolveMany(string $table, array $externalIds): array
    {
        $this->assertTable($table);
        $requested = [];
        $missing = [];
        foreach ($externalIds as $id) {
            if (!is_string($id) || !Str::isUuid($id)) {
                throw new InvalidArgumentException('La referencia externa no es un UUID válido.');
            }
            $id = strtolower($id);
            $requested[$id] = true;
            if (!isset($this->byExternal[$table][$id])) {
                $missing[] = $id;
            }
        }
        foreach (array_chunk(array_unique($missing), 1000) as $chunk) {
            foreach (DB::table($table)->whereIn('external_id', $chunk)->get(['id', 'external_id']) as $row) {
                $this->remember($table, $row);
            }
        }

        return array_intersect_key($this->byExternal[$table] ?? [], $requested);
    }

    private function remember(string $table, object $row): void
    {
        $this->byInternal[$table][(string) $row->id] = (string) $row->external_id;
        $this->byExternal[$table][(string) $row->external_id] = (string) $row->id;
    }

    private function assertTable(string $table): void
    {
        if (!in_array($table, IdentifierSchema::TABLES, true)) {
            throw new InvalidArgumentException('La tabla no tiene un identificador externo gestionado.');
        }
    }
}
