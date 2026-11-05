<?php

declare(strict_types=1);

use App\Http\Controllers\Carbon\CarbonController;
use Illuminate\Support\Facades\Route;

Route::get('/carbon', [CarbonController::class, 'index'])->name('carbon.index');
Route::post('/carbon/profiles', [CarbonController::class, 'store'])->name('carbon.store');
Route::post('/carbon/refresh', [CarbonController::class, 'refresh'])->name('carbon.refresh');

Route::get('/carbon/refresh/status', [CarbonController::class, 'progress'])->name('carbon.progress');
