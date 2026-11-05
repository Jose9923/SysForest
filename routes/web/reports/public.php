<?php

declare(strict_types=1);
use App\Http\Middleware\PublicPortal\PublicResponseHeaders;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Reports\ExportController;
use App\Http\Controllers\Reports\ReportController;

Route::withoutMiddleware('web')->middleware(PublicResponseHeaders::class)->group(function (): void {
    Route::get('/portal/reports', [ReportController::class, 'publicIndex'])
        ->middleware('throttle:public.pages')->name('public.reports.index');
    Route::middleware('throttle:public.exports')->group(function (): void {
        Route::get('/portal/reports/download/{format}', [ExportController::class, 'publicDownload'])
            ->where('format', 'csv|xlsx|geojson|pdf')->name('public.reports.download');
        Route::get('/portal/organizations/{organization}/trees/{tree}/pdf', [ExportController::class, 'publicTree'])
            ->where(['organization' => '[A-Z0-9_-]{1,40}', 'tree' => '[A-Z0-9_-]{1,100}'])->name('public.trees.pdf');
    });
});
