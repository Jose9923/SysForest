<?php

declare(strict_types=1);

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

// Las rutas reciben UUID como texto: el Service resuelve primero por tenant y luego aplica Policy.
Route::get('/trees', [C\TreeController::class, 'index'])->name('trees.index');
Route::get('/trees/create', [C\TreeController::class, 'create'])->name('trees.create');
Route::post('/trees', [C\TreeController::class, 'store'])->name('trees.store');
Route::get('/trees/{tree}', [C\TreeController::class, 'show'])->whereUuid('tree')->name('trees.show');
Route::get('/trees/{tree}/edit', [C\TreeController::class, 'edit'])->whereUuid('tree')->name('trees.edit');
Route::put('/trees/{tree}', [C\TreeController::class, 'update'])->whereUuid('tree')->name('trees.update');
Route::patch('/trees/{tree}/status', C\TreeStatusController::class)->whereUuid('tree')->name('trees.status');
Route::patch('/trees/{tree}/location', C\TreeLocationController::class)->whereUuid('tree')->name('trees.location');
Route::delete('/trees/{tree}', C\TreeVoidController::class)->whereUuid('tree')->name('trees.void');
Route::post('/trees/{tree}/restore', C\TreeRestoreController::class)->whereUuid('tree')->name('trees.restore');
Route::post('/trees/{tree}/inventories', C\TreeInventoryController::class)->whereUuid('tree')->name('trees.inventories');
Route::post('/coordinates/preview', C\CoordinatePreviewController::class)->middleware('throttle:60,1')->name('coordinates.preview');
Route::get('/inventories', [C\InventoryController::class, 'index'])->name('inventories.index');
Route::get('/inventories/create', [C\InventoryController::class, 'create'])->name('inventories.create');
Route::post('/inventories', [C\InventoryController::class, 'store'])->name('inventories.store');
Route::get('/inventories/{inventory}', [C\InventoryController::class, 'show'])->whereUuid('inventory')->name('inventories.show');
Route::get('/inventories/{inventory}/edit', [C\InventoryController::class, 'edit'])->whereUuid('inventory')->name('inventories.edit');
Route::put('/inventories/{inventory}', [C\InventoryController::class, 'update'])->whereUuid('inventory')->name('inventories.update');
