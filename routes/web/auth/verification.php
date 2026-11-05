<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\EmailVerificationController;

Route::post('/verify-email', [EmailVerificationController::class, 'store'])->middleware('throttle:identity.password')->name('verification.verify');
Route::post('/verify-email/send', [EmailVerificationController::class, 'send'])->middleware('throttle:identity.password')->name('verification.send');
