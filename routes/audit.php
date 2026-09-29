<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Repositories\AuditReadRepository;
use Illuminate\Support\Facades\Route;

Route::middleware(['can:audit.view', 'throttle:audit.read'])->group(function (): void {
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/audit/{source}/{entry}', [AuditController::class, 'show'])
        ->whereIn('source', AuditReadRepository::SOURCES)->whereUuid('entry')->name('audit.show');
});
