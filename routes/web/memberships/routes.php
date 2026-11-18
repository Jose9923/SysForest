<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Memberships\OrganizationMembershipController;

Route::get('/memberships', [OrganizationMembershipController::class, 'index'])->name('memberships.index');
Route::get('/memberships/{membership}', [OrganizationMembershipController::class, 'show'])->whereNumber('membership')->name('memberships.show');
Route::patch('/memberships/{membership}', [OrganizationMembershipController::class, 'update'])->whereNumber('membership')->name('memberships.update');
