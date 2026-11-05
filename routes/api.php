<?php

declare(strict_types=1);

use App\Http\Controllers\Mobile\MobileReferenceController;
use App\Http\Controllers\Mobile\MobileRiskController;
use App\Http\Controllers\Mobile\MobileInventoryController;
use App\Http\Controllers\Mobile\MobileMaintenanceController;
use App\Http\Controllers\Mobile\MobileTreePhotoController;
use App\Http\Controllers\Mobile\MobileTreeController;
use App\Http\Controllers\Mobile\MobileInspectionController;
use App\Http\Controllers\Mobile\MobileTreeSyncController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Middleware\AuthenticateMobileJwt;
use App\Http\Middleware\MobileApiHeaders;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->name('mobile.')->middleware(MobileApiHeaders::class)->group(function (): void {
    Route::post('auth/organizations', [MobileAuthController::class, 'organizations'])->middleware('throttle:identity.login')->name('organizations');
    Route::post('auth/login', [MobileAuthController::class, 'login'])->middleware('throttle:identity.login')->name('login');
    Route::post('auth/refresh', [MobileAuthController::class, 'refresh'])->middleware('throttle:identity.refresh')->name('refresh');
    Route::post('auth/logout', [MobileAuthController::class, 'logout'])->middleware('throttle:identity.refresh')->name('logout');

    Route::middleware(AuthenticateMobileJwt::class)->group(function (): void {
        Route::middleware('throttle:mobile.read')->group(function (): void {
            Route::get('risk/trees', [MobileRiskController::class, 'index'])->name('risk.trees');
            Route::post('risk/preview', [MobileRiskController::class, 'preview'])->name('risk.preview');
            Route::get('inventories/{inventory}/maintenances', [MobileMaintenanceController::class, 'index'])->whereUuid('inventory')->name('maintenances.index');
            Route::get('inventories/{inventory}/trees', [MobileTreeController::class, 'index'])->whereUuid('inventory')->name('trees.index');
            Route::get('trees/{tree}', [MobileTreeController::class, 'show'])->whereUuid('tree')->name('trees.show');
            Route::get('trees/{tree}/inspections', [MobileInspectionController::class, 'index'])->whereUuid('tree')->name('inspections.index');
            Route::get('inspections/{inspection}', [MobileInspectionController::class, 'show'])->whereUuid('inspection')->name('inspections.show');
            Route::get('trees/{tree}/photos', [MobileTreePhotoController::class, 'index'])->whereUuid('tree')->name('trees.photos.index');
            Route::get('photos/{photo}/content', [MobileTreePhotoController::class, 'content'])->whereUuid('photo')->name('photos.content');
            Route::get('auth/me', [MobileAuthController::class, 'me'])->name('me');
            Route::get('inventories', [MobileReferenceController::class, 'inventories'])->name('inventories');
            Route::get('parameterizations', [MobileReferenceController::class, 'manifest'])->name('parameterizations');
            Route::get('parameterizations/{catalog}', [MobileReferenceController::class, 'catalog'])->name('catalog');
        });
        Route::middleware('throttle:mobile.write')->group(function (): void {
            Route::post('inventories', [MobileInventoryController::class, 'store'])->name('inventories.store');
            Route::patch('trees/{tree}', [MobileTreeController::class, 'update'])->whereUuid('tree')->name('trees.update');
            Route::post('trees/{tree}/inspections', [MobileInspectionController::class, 'store'])->whereUuid('tree')->name('inspections.store');
            Route::patch('inspections/{inspection}', [MobileInspectionController::class, 'update'])->whereUuid('inspection')->name('inspections.update');
        });
        Route::post('trees/{tree}/photos', [MobileTreePhotoController::class, 'store'])
            ->whereUuid('tree')->middleware('throttle:mobile.write')->name('trees.photos.store');
        Route::post('inventories/{inventory}/trees/sync', [MobileTreeSyncController::class, 'store'])
            ->whereUuid('inventory')->middleware('throttle:mobile.sync')->name('trees.sync');
    });
});
