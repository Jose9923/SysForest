<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Invitations\InvitationController;

Route::get('/invitations/accept', [InvitationController::class, 'show'])->name('invitations.preview');
Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:identity.invitation')->name('invitations.accept');
