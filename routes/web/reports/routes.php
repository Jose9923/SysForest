<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Reports\ExportController;
use App\Http\Controllers\Reports\ReportController;

Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::middleware('throttle:reports.download')->group(function (): void {
    Route::get('/reports/download/{format}', [ExportController::class, 'download'])
        ->where('format', 'csv|xlsx|geojson|pdf')->name('reports.download');
    Route::get('/reports/trees/{tree}/pdf', [ExportController::class, 'tree'])
        ->whereNumber('tree')->name('reports.trees.pdf');
});
