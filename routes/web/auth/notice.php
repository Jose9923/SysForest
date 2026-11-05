<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\EmailVerificationController;

Route::get('/verify-email', [EmailVerificationController::class, 'show'])->name('verification.notice');
