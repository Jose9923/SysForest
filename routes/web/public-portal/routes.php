<?php

declare(strict_types=1);
use App\Http\Middleware\PublicPortal\PublicResponseHeaders;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicPortal\PublicEvidenceController;
use App\Http\Controllers\PublicPortal\PublicMapController;
use App\Http\Controllers\PublicPortal\PublicPortalController;
use App\Http\Controllers\PublicPortal\PublicTreeController;

// Sin sesión, cookies JWT, membership ni tenant institucional. La publicación se aplica en Repository.
Route::withoutMiddleware('web')->middleware(PublicResponseHeaders::class)->group(function (): void {
    Route::prefix('portal')->middleware('throttle:public.pages')->group(function (): void {
        Route::get('/', [PublicPortalController::class, 'home'])->name('public.home');
        Route::get('/map', [PublicPortalController::class, 'map'])->name('public.map');
        Route::get('/trees', [PublicPortalController::class, 'index'])->name('public.trees.index');
        Route::get('/organizations/{organization}/trees/{tree}', [PublicPortalController::class, 'show'])
            ->where(['organization' => '[A-Z0-9_-]{1,40}', 'tree' => '[A-Z0-9_-]{1,100}'])->name('public.trees.show');
    });
    Route::get('/portal/media/{evidence}', PublicEvidenceController::class)
        ->where('evidence', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:public.media')->name('public.evidences.file');

    Route::prefix('api/public/v1')->middleware('throttle:public.api')->group(function (): void {
        foreach (['trees', 'clusters', 'heat', 'extent', 'search'] as $endpoint) {
            Route::get('/map/'.$endpoint, [PublicMapController::class, $endpoint])->name('public.map.'.$endpoint);
        }
        Route::get('/options/{kind}', [PublicMapController::class, 'options'])->where('kind', '[a-z_]+')->name('public.options');
        Route::get('/trees', [PublicTreeController::class, 'index'])->name('public.api.trees');
        Route::get('/indicators', [PublicTreeController::class, 'indicators'])->name('public.api.indicators');
        Route::get('/organizations/{organization}/trees/{tree}', [PublicTreeController::class, 'show'])
            ->where(['organization' => '[A-Z0-9_-]{1,40}', 'tree' => '[A-Z0-9_-]{1,100}'])->name('public.api.trees.show');
    });
});
