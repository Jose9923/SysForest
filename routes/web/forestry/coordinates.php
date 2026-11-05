<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Forestry\CoordinatePreviewController;

Route::post('/coordinates/preview', CoordinatePreviewController::class)->middleware('throttle:60,1')->name('coordinates.preview');
