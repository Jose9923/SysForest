<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\PasswordEmailRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Services\Auth\IdentityPageService;
use App\Services\Auth\PasswordService;
use App\Services\Auth\WebSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Controller;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function send(PasswordEmailRequest $request, PasswordService $passwords): RedirectResponse
    {
        $passwords->requestReset($request->validated('email'));

        return back()->with('status', 'Si la cuenta puede recuperar el acceso, recibirá un enlace por correo.');
    }

    public function edit(Request $request, IdentityPageService $pages): View
    {
        try {
            return view('auth.reset-password', $pages->reset($request->query('token')));
        } catch (ValidationException) {
            return view('auth.link-invalid');
        }
    }

    public function update(PasswordResetRequest $request, PasswordService $passwords, WebSessionService $web): Response
    {
        $passwords->reset($request->validated('token'), $request->validated('password'));

        return $web->end($request, 'Contraseña restablecida. Inicia sesión con la nueva contraseña.');
    }
}
