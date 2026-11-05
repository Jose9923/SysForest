<?php

declare(strict_types=1);

use App\Http\Controllers\Maps\MapController;
use Illuminate\Support\Facades\Route;

// Estas rutas heredan JWT, sesión y verificación. El alcance amplio se reautoriza en cada Service.
Route::get('/map', [MapController::class, 'index'])->name('maps.index');
Route::post('/map/trees/{tree}/open', [MapController::class, 'open'])->whereUuid('tree')->name('maps.open');
Route::prefix('/api/v1/map')->middleware('throttle:maps.read')->group(function (): void {
    Route::get('/trees', [MapController::class, 'trees'])->name('maps.trees');
    Route::get('/clusters', [MapController::class, 'clusters'])->name('maps.clusters');
    Route::get('/heat', [MapController::class, 'heat'])->name('maps.heat');
    Route::get('/extent', [MapController::class, 'extent'])->name('maps.extent');
    Route::get('/search', [MapController::class, 'search'])->name('maps.search');
    Route::get('/options/{kind}', [MapController::class, 'options'])->where('kind', '[a-z_]+')->name('maps.options');
});
