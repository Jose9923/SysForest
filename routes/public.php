<?php

declare(strict_types=1);

use App\Http\Controllers as C;
use App\Http\Middleware\PublicResponseHeaders;
use Illuminate\Support\Facades\Route;

// Sin sesión, cookies JWT, membership ni tenant institucional. La publicación se aplica en Repository.
Route::withoutMiddleware('web')->middleware(PublicResponseHeaders::class)->group(function (): void {
    Route::prefix('portal')->middleware('throttle:public.pages')->group(function (): void {
        Route::get('/', [C\PublicPortalController::class, 'home'])->name('public.home');
        Route::get('/map', [C\PublicPortalController::class, 'map'])->name('public.map');
        Route::get('/trees', [C\PublicPortalController::class, 'index'])->name('public.trees.index');
        Route::get('/organizations/{organization}/trees/{tree}', [C\PublicPortalController::class, 'show'])
            ->where(['organization' => '[A-Z0-9_-]{1,40}', 'tree' => '[A-Z0-9_-]{1,100}'])->name('public.trees.show');
    });
    Route::get('/portal/media/{evidence}', C\PublicEvidenceController::class)
        ->where('evidence', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:public.media')->name('public.evidences.file');

    Route::prefix('api/public/v1')->middleware('throttle:public.api')->group(function (): void {
        foreach (['trees', 'clusters', 'heat', 'extent', 'search'] as $endpoint) {
            Route::get('/map/'.$endpoint, [C\PublicMapController::class, $endpoint])->name('public.map.'.$endpoint);
        }
        Route::get('/options/{kind}', [C\PublicMapController::class, 'options'])->where('kind', '[a-z_]+')->name('public.options');
        Route::get('/trees', [C\PublicTreeController::class, 'index'])->name('public.api.trees');
        Route::get('/indicators', [C\PublicTreeController::class, 'indicators'])->name('public.api.indicators');
        Route::get('/organizations/{organization}/trees/{tree}', [C\PublicTreeController::class, 'show'])
            ->where(['organization' => '[A-Z0-9_-]{1,40}', 'tree' => '[A-Z0-9_-]{1,100}'])->name('public.api.trees.show');
    });
});
