<?php

declare(strict_types=1);

namespace App\Models\Catalogs;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

/** Metadatos compartidos por valores nacionales independientes, sin pertenencia institucional. */
abstract class CatalogEntry extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['code', 'name', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer'];
    }
}
