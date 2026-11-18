<?php

declare(strict_types=1);

use App\Http\Controllers\Imports\ImportController;
use Illuminate\Support\Facades\Route;

// El grupo padre exige sesión verificada y tenant activo; los UUID se resuelven dentro de esa institución.
Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
Route::get('/imports/create', [ImportController::class, 'create'])->name('imports.create');
Route::get('/imports/template', [ImportController::class, 'template'])->middleware('throttle:12,1')->name('imports.template');
Route::post('/imports', [ImportController::class, 'store'])->middleware('throttle:imports.write')->name('imports.store');
Route::get('/imports/{batch}', [ImportController::class, 'show'])->whereNumber('batch')->name('imports.show');
Route::post('/imports/{batch}/confirm', [ImportController::class, 'confirm'])->whereNumber('batch')->middleware('throttle:imports.write')->name('imports.confirm');
Route::post('/imports/{batch}/cancel', [ImportController::class, 'cancel'])->whereNumber('batch')->middleware('throttle:imports.write')->name('imports.cancel');
