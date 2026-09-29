<?php

declare(strict_types=1);

use App\Http\Controllers as C;
use App\Http\Middleware\PublicResponseHeaders;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware('web')->middleware(PublicResponseHeaders::class)->group(function (): void {
    Route::get('/portal/reports', [C\ReportController::class, 'publicIndex'])
        ->middleware('throttle:public.pages')->name('public.reports.index');
    Route::middleware('throttle:public.exports')->group(function (): void {
        Route::get('/portal/reports/download/{format}', [C\ExportController::class, 'publicDownload'])
            ->where('format', 'csv|xlsx|geojson|pdf')->name('public.reports.download');
        Route::get('/portal/organizations/{organization}/trees/{tree}/pdf', [C\ExportController::class, 'publicTree'])
            ->where(['organization' => '[A-Z0-9_-]{1,40}', 'tree' => '[A-Z0-9_-]{1,100}'])->name('public.trees.pdf');
    });
});
