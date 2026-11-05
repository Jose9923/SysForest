<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Users\UserController;

Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
Route::put('/users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
Route::post('/users/{user}/roles', [UserController::class, 'roles'])->whereNumber('user')->name('users.roles');
