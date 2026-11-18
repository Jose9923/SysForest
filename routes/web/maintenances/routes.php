<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Maintenances\MaintenanceController;

Route::get('/maintenances', [MaintenanceController::class, 'index'])->name('maintenances.index');
Route::get('/trees/{tree}/maintenances/create', [MaintenanceController::class, 'create'])->whereNumber('tree')->name('trees.maintenances.create');
Route::post('/trees/{tree}/maintenances', [MaintenanceController::class, 'store'])->whereNumber('tree')->name('trees.maintenances.store');
Route::get('/maintenances/{maintenance}', [MaintenanceController::class, 'show'])->whereNumber('maintenance')->name('maintenances.show');
Route::get('/maintenances/{maintenance}/edit', [MaintenanceController::class, 'edit'])->whereNumber('maintenance')->name('maintenances.edit');
Route::put('/maintenances/{maintenance}', [MaintenanceController::class, 'update'])->whereNumber('maintenance')->name('maintenances.update');
Route::patch('/maintenances/{maintenance}/status', [MaintenanceController::class, 'transition'])->whereNumber('maintenance')->name('maintenances.status');
Route::delete('/maintenances/{maintenance}', [MaintenanceController::class, 'destroy'])->whereNumber('maintenance')->name('maintenances.void');
Route::post('/maintenances/{maintenance}/restore', [MaintenanceController::class, 'restore'])->whereNumber('maintenance')->name('maintenances.restore');
