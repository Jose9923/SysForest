<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Organizations\OrganizationAdministrationController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\TenantSelectionController;

Route::post('/active-organization', TenantSelectionController::class)->name('tenant.select');
Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
Route::get('/administration/organizations/{organization}/edit', [OrganizationAdministrationController::class, 'edit'])->whereUuid('organization')->name('administration.organizations.edit');
Route::put('/administration/organizations/{organization}', [OrganizationAdministrationController::class, 'update'])->whereUuid('organization')->name('administration.organizations.update');
