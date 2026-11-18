<?php

declare(strict_types=1);

use App\Http\Controllers\Publication\PublicationController;
use Illuminate\Support\Facades\Route;

// Incluido exclusivamente dentro del grupo privado con JWT, verificación y tenant.
Route::get('/publication', [PublicationController::class, 'index'])->name('publication.index');
Route::patch('/publication/organization', [PublicationController::class, 'organization'])->name('publication.organizations.update');
Route::patch('/publication/inventories/{inventory}', [PublicationController::class, 'inventory'])->whereNumber('inventory')->name('publication.inventories.update');
Route::patch('/publication/trees/{tree}', [PublicationController::class, 'tree'])->whereNumber('tree')->name('publication.trees.update');
Route::patch('/publication/inspections/{inspection}', [PublicationController::class, 'inspection'])->whereNumber('inspection')->name('publication.inspections.update');
Route::patch('/publication/evidences/{evidence}', [PublicationController::class, 'evidence'])->whereNumber('evidence')->name('publication.evidences.update');
