<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RefreshController;
use App\Http\Controllers\Auth\SessionContinuityController;

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:identity.login')->name('login.store');
Route::post('/logout', LogoutController::class)->name('logout');
Route::get('/auth/session/status', [SessionContinuityController::class, 'status'])->name('auth.session.status');
Route::post('/auth/session/ensure', [SessionContinuityController::class, 'ensure'])->middleware('throttle:identity.refresh')->name('auth.session.ensure');
Route::get('/auth/renew', [RefreshController::class, 'create'])->name('auth.renew');
Route::post('/auth/refresh', [RefreshController::class, 'store'])->middleware('throttle:identity.refresh')->name('auth.refresh');
Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:identity.password')->name('password.email');
Route::get('/reset-password', [PasswordResetController::class, 'edit'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:identity.password')->name('password.store');
