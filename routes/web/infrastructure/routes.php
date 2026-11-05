<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Infrastructure\SystemStatusController;

Route::withoutMiddleware('web')->group(function (): void {
    Route::get('/', [SystemStatusController::class, 'index'])->name('system.home');
    Route::get('/health/ready', [SystemStatusController::class, 'readiness'])->name('system.readiness');
});
