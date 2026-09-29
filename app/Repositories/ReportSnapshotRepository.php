<?php

declare(strict_types=1);

namespace App\Repositories;

use Closure;
use Illuminate\Support\Facades\DB;

/** Evita mezclar generaciones de datos cuando un archivo requiere varios lotes de lectura. */
class ReportSnapshotRepository
{
    public function read(Closure $callback): mixed
    {
        // Los tests ya contienen una transacción externa; PostgreSQL no permite cambiar su aislamiento aquí.
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction(function () use ($callback): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $callback();
        });
    }
}
