<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('taxonomy')->name('taxonomy.')->where(['catalog' => 'families|species|common-names'])->group(function (): void {
    Route::get('/{catalog}', [\App\Http\Controllers\Taxonomy\TaxonomyController::class, 'index'])->name('index');
    Route::get('/{catalog}/create', [\App\Http\Controllers\Taxonomy\TaxonomyController::class, 'create'])->name('create');
    Route::post('/{catalog}', [\App\Http\Controllers\Taxonomy\TaxonomyController::class, 'store'])->name('store');
    Route::get('/{catalog}/{record}/edit', [\App\Http\Controllers\Taxonomy\TaxonomyController::class, 'edit'])->whereNumber('record')->name('edit');
    Route::put('/{catalog}/{record}', [\App\Http\Controllers\Taxonomy\TaxonomyController::class, 'update'])->whereNumber('record')->name('update');
});
