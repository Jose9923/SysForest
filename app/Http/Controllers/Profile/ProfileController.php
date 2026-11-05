<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Http\Requests\Profile\PasswordChangeRequest;
use App\Http\Requests\Profile\ProfileUpdateRequest;
use App\Services\Auth\PasswordService;
use App\Services\Users\UserService;
use App\Services\Auth\WebSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Controller;

class ProfileController extends Controller
{
    public function show(Request $request, UserService $users): View
    {
        return view('profile.show', $users->profile($request->user(), $request->attributes->get('auth_session')->id));
    }

    public function update(ProfileUpdateRequest $request, UserService $users): RedirectResponse
    {
        $users->updateProfile($request->user(), $request->validated());

        return back()->with('status', 'Perfil actualizado.');
    }

    public function password(PasswordChangeRequest $request, PasswordService $passwords, WebSessionService $web): Response
    {
        $passwords->change($request->user(), $request->validated('current_password'), $request->validated('password'));

        return $web->end($request, 'Contraseña actualizada. Todas las sesiones fueron revocadas.');
    }

    public function destroySession(Request $request, string $session, UserService $users, WebSessionService $web): Response
    {
        $users->revokeOwnSession($request->user(), $session);
        if ($session === $request->attributes->get('auth_session')->id) {
            return $web->end($request);
        }

        return back()->with('status', 'Sesión revocada.');
    }
}
