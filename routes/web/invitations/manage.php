<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Invitations\InvitationController;

Route::get('/invitations/create', [InvitationController::class, 'create'])->name('invitations.create');
Route::post('/invitations', [InvitationController::class, 'store'])->middleware('throttle:identity.invitation')->name('invitations.store');
