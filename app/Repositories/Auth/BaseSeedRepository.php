<?php

declare(strict_types=1);

namespace App\Repositories\Auth;

use Illuminate\Support\Facades\DB;

class BaseSeedRepository
{
    /** Serializa la preparación incluso cuando todavía no existe ninguna cuenta o catálogo. */
    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sysforest:base-seed']);
    }
}
