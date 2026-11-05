<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Profile\ProfileController;

Route::get('/profile', [ProfileController::class, 'show'])->middleware('tenant.share')->name('profile.show');
Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
Route::put('/profile/password', [ProfileController::class, 'password'])->middleware('throttle:identity.password')->name('password.change');
Route::delete('/profile/sessions/{session}', [ProfileController::class, 'destroySession'])->whereUuid('session')->name('sessions.destroy');
