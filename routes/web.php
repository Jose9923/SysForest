<?php

declare(strict_types=1);

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

require __DIR__.'/public.php';

// Disponibilidad técnica independiente de Redis y de las sesiones de usuario.
Route::withoutMiddleware('web')->group(function (): void {
    Route::get('/', [C\SystemStatusController::class, 'index'])->name('system.home');
    Route::get('/health/ready', [C\SystemStatusController::class, 'readiness'])->name('system.readiness');
});

Route::middleware('identity.headers')->group(function (): void {
    Route::get('/login', [C\LoginController::class, 'create'])->name('login');
    Route::post('/login', [C\LoginController::class, 'store'])->middleware('throttle:identity.login')->name('login.store');
    Route::post('/logout', C\LogoutController::class)->name('logout');
    Route::get('/auth/renew', [C\RefreshController::class, 'create'])->name('auth.renew');
    Route::post('/auth/refresh', [C\RefreshController::class, 'store'])->middleware('throttle:identity.refresh')->name('auth.refresh');
    Route::get('/forgot-password', [C\PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [C\PasswordResetController::class, 'send'])->middleware('throttle:identity.password')->name('password.email');
    Route::get('/reset-password', [C\PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [C\PasswordResetController::class, 'update'])->middleware('throttle:identity.password')->name('password.store');
    Route::middleware('jwt.optional')->group(function (): void {
        Route::get('/verify-email', [C\EmailVerificationController::class, 'show'])->name('verification.notice');
        Route::get('/invitations/accept', [C\InvitationController::class, 'show'])->name('invitations.preview');
        Route::post('/invitations/accept', [C\InvitationController::class, 'accept'])->middleware('throttle:identity.invitation')->name('invitations.accept');
    });
    Route::middleware(['jwt.cookie', 'identity.session'])->group(function (): void {
        Route::post('/verify-email', [C\EmailVerificationController::class, 'store'])->middleware('throttle:identity.password')->name('verification.verify');
        Route::post('/verify-email/send', [C\EmailVerificationController::class, 'send'])->middleware('throttle:identity.password')->name('verification.send');
        Route::get('/profile', [C\ProfileController::class, 'show'])->middleware('tenant.share')->name('profile.show');
        Route::put('/profile', [C\ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [C\ProfileController::class, 'password'])->middleware('throttle:identity.password')->name('password.change');
        Route::delete('/profile/sessions/{session}', [C\ProfileController::class, 'destroySession'])->whereUuid('session')->name('sessions.destroy');

        Route::middleware(['identity.verified', 'tenant.share'])->group(function (): void {
            require __DIR__.'/catalogs.php';
            require __DIR__.'/maps.php';
            Route::get('/territories', C\TerritoryController::class)->name('territories.index');
            Route::post('/active-organization', C\TenantSelectionController::class)->name('tenant.select');
            Route::get('/organizations', [C\OrganizationController::class, 'index'])->name('organizations.index');
            Route::get('/organizations/create', [C\OrganizationController::class, 'create'])->name('organizations.create');
            Route::post('/organizations', [C\OrganizationController::class, 'store'])->name('organizations.store');
            Route::get('/administration/organizations/{organization}/edit', [C\OrganizationAdministrationController::class, 'edit'])->whereUuid('organization')->name('administration.organizations.edit');
            Route::put('/administration/organizations/{organization}', [C\OrganizationAdministrationController::class, 'update'])->whereUuid('organization')->name('administration.organizations.update');
            Route::get('/users', [C\UserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [C\UserController::class, 'show'])->whereNumber('user')->name('users.show');
            Route::put('/users/{user}', [C\UserController::class, 'update'])->whereNumber('user')->name('users.update');
            Route::post('/users/{user}/roles', [C\UserController::class, 'roles'])->whereNumber('user')->name('users.roles');
            Route::middleware('tenant.context')->group(function (): void {
                require __DIR__.'/forestry.php';
                require __DIR__.'/publication.php';
                Route::get('/organizations/{organization}', [C\OrganizationController::class, 'show'])->whereUuid('organization')->name('organizations.show');
                Route::get('/organizations/{organization}/edit', [C\OrganizationController::class, 'edit'])->whereUuid('organization')->name('organizations.edit');
                Route::put('/organizations/{organization}', [C\OrganizationController::class, 'update'])->whereUuid('organization')->name('organizations.update');
                Route::get('/memberships', [C\OrganizationMembershipController::class, 'index'])->name('memberships.index');
                Route::get('/memberships/{membership}', [C\OrganizationMembershipController::class, 'show'])->whereUuid('membership')->name('memberships.show');
                Route::patch('/memberships/{membership}', [C\OrganizationMembershipController::class, 'update'])->whereUuid('membership')->name('memberships.update');
                Route::get('/invitations/create', [C\InvitationController::class, 'create'])->name('invitations.create');
                Route::post('/invitations', [C\InvitationController::class, 'store'])->middleware('throttle:identity.invitation')->name('invitations.store');
            });
        });
    });
});
