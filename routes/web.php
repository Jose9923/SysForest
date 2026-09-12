<?php

declare(strict_types=1);

use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

// Estas vistas técnicas no usan sesión: Redis caído debe producir un estado controlado.
Route::withoutMiddleware('web')->group(function (): void {
    Route::get('/', [SystemStatusController::class, 'index'])->name('system.home');
    Route::get('/health/ready', [SystemStatusController::class, 'readiness'])->name('system.readiness');
});

Route::get('/phase-3/access', [\App\Http\Controllers\LocalAccessController::class, 'info'])->name('phase3.access');
Route::get('/phase-3/local-access/{token}', [\App\Http\Controllers\LocalAccessController::class, 'consume'])
    ->where('token', '[a-f0-9]{64}')->middleware(['signed', 'throttle:20,1'])->name('phase3.local.consume');

Route::middleware(['auth', 'local.access'])->group(function (): void {
    Route::post('/phase-3/logout', [\App\Http\Controllers\LocalAccessController::class, 'logout'])->name('phase3.logout');
    Route::get('/territories', \App\Http\Controllers\TerritoryController::class)->name('territories.index');
    Route::post('/active-organization', \App\Http\Controllers\TenantSelectionController::class)->name('tenant.select');
    Route::get('/organizations', [\App\Http\Controllers\OrganizationController::class, 'index'])->name('organizations.index');
    Route::get('/organizations/create', [\App\Http\Controllers\OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [\App\Http\Controllers\OrganizationController::class, 'store'])->name('organizations.store');

    Route::middleware('tenant.context')->group(function (): void {
        Route::get('/organizations/{organization}', [\App\Http\Controllers\OrganizationController::class, 'show'])->whereUuid('organization')->name('organizations.show');
        Route::get('/organizations/{organization}/edit', [\App\Http\Controllers\OrganizationController::class, 'edit'])->whereUuid('organization')->name('organizations.edit');
        Route::put('/organizations/{organization}', [\App\Http\Controllers\OrganizationController::class, 'update'])->whereUuid('organization')->name('organizations.update');
        Route::get('/memberships/{membership}', [\App\Http\Controllers\OrganizationMembershipController::class, 'show'])->whereUuid('membership')->name('memberships.show');
        Route::patch('/memberships/{membership}', [\App\Http\Controllers\OrganizationMembershipController::class, 'update'])->whereUuid('membership')->name('memberships.update');
    });
});
