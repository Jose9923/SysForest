<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Organizations\OrganizationController;

Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->whereNumber('organization')->name('organizations.show');
Route::get('/organizations/{organization}/edit', [OrganizationController::class, 'edit'])->whereNumber('organization')->name('organizations.edit');
Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->whereNumber('organization')->name('organizations.update');
