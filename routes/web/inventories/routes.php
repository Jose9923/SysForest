<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Inventories\InventoryController;

Route::get('/inventories', [InventoryController::class, 'index'])->name('inventories.index');
Route::get('/inventories/create', [InventoryController::class, 'create'])->name('inventories.create');
Route::post('/inventories', [InventoryController::class, 'store'])->name('inventories.store');
Route::get('/inventories/{inventory}', [InventoryController::class, 'show'])->whereNumber('inventory')->name('inventories.show');
Route::get('/inventories/{inventory}/edit', [InventoryController::class, 'edit'])->whereNumber('inventory')->name('inventories.edit');
Route::put('/inventories/{inventory}', [InventoryController::class, 'update'])->whereNumber('inventory')->name('inventories.update');
