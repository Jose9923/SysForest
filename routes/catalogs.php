<?php

declare(strict_types=1);

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

// Hereda JWT, cuenta verificada y contexto visual opcional; no exige un tenant seleccionado.
Route::prefix('admin/catalogs')->group(function (): void {
    Route::get('/', [CatalogController::class, 'dashboard'])->name('catalogs.dashboard');
    Route::prefix('taxonomy')->name('taxonomy.')->where(['catalog' => 'families|species|common-names'])->group(function (): void {
        Route::get('/{catalog}', [\App\Http\Controllers\TaxonomyController::class, 'index'])->name('index');
        Route::get('/{catalog}/create', [\App\Http\Controllers\TaxonomyController::class, 'create'])->name('create');
        Route::post('/{catalog}', [\App\Http\Controllers\TaxonomyController::class, 'store'])->name('store');
        Route::get('/{catalog}/{record}/edit', [\App\Http\Controllers\TaxonomyController::class, 'edit'])->whereUuid('record')->name('edit');
        Route::put('/{catalog}/{record}', [\App\Http\Controllers\TaxonomyController::class, 'update'])->whereUuid('record')->name('update');
    });
    Route::prefix('conditions')->name('conditions.')->where(['catalog' => 'categories|items'])->group(function (): void {
        Route::get('/{catalog}', [\App\Http\Controllers\ConditionCatalogController::class, 'index'])->name('index');
        Route::get('/{catalog}/create', [\App\Http\Controllers\ConditionCatalogController::class, 'create'])->name('create');
        Route::post('/{catalog}', [\App\Http\Controllers\ConditionCatalogController::class, 'store'])->name('store');
        Route::get('/{catalog}/{record}/edit', [\App\Http\Controllers\ConditionCatalogController::class, 'edit'])->whereUuid('record')->name('edit');
        Route::put('/{catalog}/{record}', [\App\Http\Controllers\ConditionCatalogController::class, 'update'])->whereUuid('record')->name('update');
    });
    Route::get('/rules', [\App\Http\Controllers\ClassificationCatalogController::class, 'index'])->name('classification.index');
    Route::get('/rules/{version}', [\App\Http\Controllers\ClassificationCatalogController::class, 'show'])->whereUuid('version')->name('classification.show');
    Route::get('/rules/{version}/edit', [\App\Http\Controllers\ClassificationCatalogController::class, 'edit'])->whereUuid('version')->name('classification.edit');
    Route::put('/rules/{version}', [\App\Http\Controllers\ClassificationCatalogController::class, 'update'])->whereUuid('version')->name('classification.update');
    Route::where(['catalog' => 'placements|establishment-origins|biogeographic-origins|growth-habits|maturity-states|evidence-types|maintenance-types|deletion-reasons|fauna-categories'])->group(function (): void {
        Route::get('/{catalog}', [CatalogController::class, 'index'])->name('catalogs.index');
        Route::get('/{catalog}/create', [CatalogController::class, 'create'])->name('catalogs.create');
        Route::post('/{catalog}', [CatalogController::class, 'store'])->name('catalogs.store');
        Route::get('/{catalog}/{entry}/edit', [CatalogController::class, 'edit'])->whereUuid('entry')->name('catalogs.edit');
        Route::put('/{catalog}/{entry}', [CatalogController::class, 'update'])->whereUuid('entry')->name('catalogs.update');
    });
});
