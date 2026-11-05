<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Territories\TerritoryController;

Route::get('/territories', TerritoryController::class)->name('territories.index');
