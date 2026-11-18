<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('conditions')->name('conditions.')->where(['catalog' => 'categories|items'])->group(function (): void {
    Route::get('/{catalog}', [\App\Http\Controllers\Conditions\ConditionCatalogController::class, 'index'])->name('index');
    Route::get('/{catalog}/create', [\App\Http\Controllers\Conditions\ConditionCatalogController::class, 'create'])->name('create');
    Route::post('/{catalog}', [\App\Http\Controllers\Conditions\ConditionCatalogController::class, 'store'])->name('store');
    Route::get('/{catalog}/{record}/edit', [\App\Http\Controllers\Conditions\ConditionCatalogController::class, 'edit'])->whereNumber('record')->name('edit');
    Route::put('/{catalog}/{record}', [\App\Http\Controllers\Conditions\ConditionCatalogController::class, 'update'])->whereNumber('record')->name('update');
});
