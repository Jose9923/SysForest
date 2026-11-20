<?php

declare(strict_types=1);

namespace App\Repositories\Taxonomy;

use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Support\Identifiers\RecordIdentifier;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Códigos nacionales estables y equivalencias de códigos anteriores. */
class TaxonomyCodeRepository
{
    public function resolveId(TaxonomyCatalog $catalog, string $code): ?int
    {
        [$table, $column] = $this->reference($catalog);
        $id = DB::table($table)->where('code', $code)->value('id');
        if ($id === null) {
            $id = DB::table('taxonomy_code_aliases')->where('catalog', $catalog->value)
                ->where('code', $code)->value($column);
        }

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, int> Código anterior => identidad interna vigente. */
    public function aliasesFor(TaxonomyCatalog $catalog, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [, $column] = $this->reference($catalog);

        return DB::table('taxonomy_code_aliases')->where('catalog', $catalog->value)
            ->whereIn($column, $ids)->orderBy('code')->pluck($column, 'code')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /** Serializa resolución, reserva y alias dentro de una misma siembra nacional. */
    public function lockCatalog(TaxonomyCatalog $catalog): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Bloquear códigos taxonómicos requiere una transacción.');
        }
        $row = DB::table('taxonomy_code_sequences')->where('catalog', $catalog->value)->lockForUpdate()->first();
        if ($row === null) {
            throw new LogicException('Falta el contador taxonómico; aplica primero la migración correspondiente.');
        }
    }

    /** Registra la procedencia sin reemplazar un código actual ni una equivalencia anterior. */
    public function registerAlias(TaxonomyCatalog $catalog, string $sourceCode, int $id): void
    {
        if (!RecordIdentifier::isValid($id) || preg_match('/\A[A-Z][A-Z0-9_]{1,79}\z/D', $sourceCode) !== 1) {
            throw new LogicException('La referencia del alias taxonómico no es válida.');
        }
        $this->lockCatalog($catalog);
        [$table, $column] = $this->reference($catalog);
        $record = DB::table($table)->where('id', $id)->first(['id', 'code']);
        if ($record === null) {
            throw new LogicException('El alias debe apuntar a un registro existente del mismo catálogo.');
        }
        $current = DB::table($table)->where('code', $sourceCode)->value('id');
        $alias = DB::table('taxonomy_code_aliases')->where('catalog', $catalog->value)
            ->where('code', $sourceCode)->value($column);
        if (($current !== null && (int) $current !== $id) || ($alias !== null && (int) $alias !== $id)) {
            throw new LogicException('El código de procedencia ya identifica otro registro taxonómico.');
        }
        if ($record->code === $sourceCode || $alias !== null) {
            return;
        }
        DB::table('taxonomy_code_aliases')->insert([
            'catalog' => $catalog->value, 'code' => $sourceCode, $column => $id,
        ]);
    }

    /** La reserva y el alta se confirman juntas; también se excluyen los alias históricos. */
    public function nextCode(TaxonomyCatalog $catalog): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La reserva de un código taxonómico requiere una transacción.');
        }
        $prefix = match ($catalog) {
            TaxonomyCatalog::Families => 'FAMILY_',
            TaxonomyCatalog::Species => 'SPECIES_',
            TaxonomyCatalog::CommonNames => 'COMMON_',
        };
        do {
            $row = DB::selectOne(
                'INSERT INTO taxonomy_code_sequences (catalog, value) VALUES (?, 1)
                 ON CONFLICT (catalog) DO UPDATE SET value = taxonomy_code_sequences.value + 1
                 WHERE taxonomy_code_sequences.value < 9223372036854775807 RETURNING value',
                [$catalog->value],
            );
            if ($row === null) {
                throw new LogicException('El consecutivo taxonómico alcanzó su límite.');
            }
            $code = $prefix.str_pad((string) $row->value, 6, '0', STR_PAD_LEFT);
        } while ($this->resolveId($catalog, $code) !== null);

        return $code;
    }

    /** @return array{string, string} */
    private function reference(TaxonomyCatalog $catalog): array
    {
        return match ($catalog) {
            TaxonomyCatalog::Families => ['families', 'family_id'],
            TaxonomyCatalog::Species => ['species', 'species_id'],
            TaxonomyCatalog::CommonNames => ['species_common_names', 'common_name_id'],
        };
    }
}
