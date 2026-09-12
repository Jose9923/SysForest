<?php

declare(strict_types=1);

use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

// Estas vistas técnicas no usan sesión: Redis caído debe producir un estado controlado.
Route::withoutMiddleware('web')->group(function (): void {
    Route::get('/', [SystemStatusController::class, 'index'])->name('system.home');
    Route::get('/health/ready', [SystemStatusController::class, 'readiness'])->name('system.readiness');
});
