<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Forestry\DendrometryPreviewController;

Route::post('/dendrometry/preview', DendrometryPreviewController::class)->middleware('throttle:60,1')->name('dendrometry.preview');
