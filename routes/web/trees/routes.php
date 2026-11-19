<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Trees\TreeController;
use App\Http\Controllers\Trees\TreeFollowUpController;
use App\Http\Controllers\Trees\TreeInventoryController;
use App\Http\Controllers\Trees\TreeLocationController;
use App\Http\Controllers\Trees\TreeRestoreController;
use App\Http\Controllers\Trees\TreeStatusController;
use App\Http\Controllers\Trees\TreeVoidController;

Route::get('/trees', [TreeController::class, 'index'])->name('trees.index');
Route::get('/trees/follow-up', TreeFollowUpController::class)->name('trees.follow-up');
Route::get('/trees/create', [TreeController::class, 'create'])->name('trees.create');
Route::post('/trees', [TreeController::class, 'store'])->name('trees.store');
Route::get('/trees/{tree}', [TreeController::class, 'show'])->whereNumber('tree')->name('trees.show');
Route::get('/trees/{tree}/edit', [TreeController::class, 'edit'])->whereNumber('tree')->name('trees.edit');
Route::put('/trees/{tree}', [TreeController::class, 'update'])->whereNumber('tree')->name('trees.update');
Route::patch('/trees/{tree}/status', TreeStatusController::class)->whereNumber('tree')->name('trees.status');
Route::patch('/trees/{tree}/location', TreeLocationController::class)->whereNumber('tree')->name('trees.location');
Route::delete('/trees/{tree}', TreeVoidController::class)->whereNumber('tree')->name('trees.void');
Route::post('/trees/{tree}/restore', TreeRestoreController::class)->whereNumber('tree')->name('trees.restore');
Route::post('/trees/{tree}/inventories', TreeInventoryController::class)->whereNumber('tree')->name('trees.inventories');
