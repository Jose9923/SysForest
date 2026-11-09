<?php

declare(strict_types=1);

namespace App\Models\Catalogs;

/** Causas nacionales del cambio de ubicación, independientes del estado del árbol. */
class LocationChangeType extends CatalogEntry
{
    protected $table = 'location_change_types';
}
