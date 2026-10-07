<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;

// Las consultas heredan la sesión web, JWT y verificación; los servicios reautorizan cada dominio.
Route::middleware('throttle:maps.read')->group(function (): void {
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/api/v1/analytics', [AnalyticsController::class, 'data'])->name('analytics.data');
});
