<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Organizations\OrganizationController;

Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->whereUuid('organization')->name('organizations.show');
Route::get('/organizations/{organization}/edit', [OrganizationController::class, 'edit'])->whereUuid('organization')->name('organizations.edit');
Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->whereUuid('organization')->name('organizations.update');
