<?php

declare(strict_types=1);

namespace App\Models\Catalogs;

/** Tipos de intervención; las órdenes se incorporarán en otra fase. */
class MaintenanceType extends CatalogEntry
{
    protected $table = 'maintenance_types';
}
