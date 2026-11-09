<?php

declare(strict_types=1);

namespace App\Repositories\Catalogs;

use App\Models\Catalogs\LocationChangeType;
use Illuminate\Support\Facades\DB;

/** Códigos estables del catálogo, sin pertenencia institucional. */
class LocationChangeTypeRepository
{
    /** Impide desactivar una opción mientras se confirma un cambio que la utiliza. */
    public function isActiveCode(string $code): bool
    {
        $query = LocationChangeType::query()->where('code', $code);
        $entry = (DB::transactionLevel() > 0 ? $query->sharedLock() : $query)->first();

        return $entry !== null && $entry->active;
    }

    /** @return array<string, string> Opciones disponibles para nuevas selecciones. */
    public function options(): array
    {
        return LocationChangeType::query()->where('active', true)
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->pluck('name', 'code')->all();
    }

    /** @return array<string, string> Incluye inactivos para consultar historias existentes. */
    public function labels(): array
    {
        return LocationChangeType::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->pluck('name', 'code')->all();
    }
}
