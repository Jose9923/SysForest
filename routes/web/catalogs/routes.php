<?php

declare(strict_types=1);

use App\Http\Controllers\Catalogs\CatalogController;
use Illuminate\Support\Facades\Route;

// Hereda JWT, cuenta verificada y contexto visual opcional; no exige un tenant seleccionado.
Route::prefix('admin/catalogs')->group(function (): void {
    Route::get('/', [CatalogController::class, 'dashboard'])->name('catalogs.dashboard');
    require __DIR__.'/../taxonomy/routes.php';
    require __DIR__.'/../conditions/routes.php';
    Route::get('/rules', [\App\Http\Controllers\Catalogs\ClassificationCatalogController::class, 'index'])->name('classification.index');
    Route::get('/rules/{version}', [\App\Http\Controllers\Catalogs\ClassificationCatalogController::class, 'show'])->whereUuid('version')->name('classification.show');
    Route::get('/rules/{version}/edit', [\App\Http\Controllers\Catalogs\ClassificationCatalogController::class, 'edit'])->whereUuid('version')->name('classification.edit');
    Route::put('/rules/{version}', [\App\Http\Controllers\Catalogs\ClassificationCatalogController::class, 'update'])->whereUuid('version')->name('classification.update');
    Route::where(['catalog' => 'location-change-types|placements|establishment-origins|biogeographic-origins|growth-habits|maturity-states|evidence-types|maintenance-types|deletion-reasons|fauna-categories'])->group(function (): void {
        Route::get('/{catalog}', [CatalogController::class, 'index'])->name('catalogs.index');
        Route::get('/{catalog}/create', [CatalogController::class, 'create'])->name('catalogs.create');
        Route::post('/{catalog}', [CatalogController::class, 'store'])->name('catalogs.store');
        Route::get('/{catalog}/{entry}/edit', [CatalogController::class, 'edit'])->whereUuid('entry')->name('catalogs.edit');
        Route::put('/{catalog}/{entry}', [CatalogController::class, 'update'])->whereUuid('entry')->name('catalogs.update');
    });
});
