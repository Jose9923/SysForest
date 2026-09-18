<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Evidence;
use App\Models\EvidenceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class EvidenceTypeRepository
{
    public function options(?Evidence $evidence = null): Collection
    {
        return EvidenceType::query()->where(function (Builder $query) use ($evidence): void {
            $query->where('active', true);
            if ($evidence !== null) {
                $query->orWhereKey($evidence->evidence_type_id);
            }
        })->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
    }

    public function optionsForFilter(): Collection
    {
        return EvidenceType::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
    }

    /** currentId procede de la evidencia autorizada, nunca de un campo libre del navegador. */
    public function validate(string $id, ?string $currentId = null, bool $lock = false): bool
    {
        if (!Str::isUuid($id)) {
            return false;
        }
        if ($lock && DB::transactionLevel() < 1) {
            throw new LogicException('Bloquear el tipo requiere la transacción de la evidencia.');
        }
        $query = EvidenceType::query()->whereKey($id);
        $type = ($lock ? $query->sharedLock() : $query)->first();

        return $type !== null && ($type->active || ($currentId !== null && strtolower($id) === strtolower($currentId)));
    }
}
