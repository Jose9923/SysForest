<?php

declare(strict_types=1);

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

Route::get('/reports', [C\ReportController::class, 'index'])->name('reports.index');
Route::middleware('throttle:reports.download')->group(function (): void {
    Route::get('/reports/download/{format}', [C\ExportController::class, 'download'])
        ->where('format', 'csv|xlsx|geojson|pdf')->name('reports.download');
    Route::get('/reports/trees/{tree}/pdf', [C\ExportController::class, 'tree'])
        ->whereUuid('tree')->name('reports.trees.pdf');
});
