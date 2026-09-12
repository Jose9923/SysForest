<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use App\Services\WebSessionService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuthService $auth, WebSessionService $web): Response
    {
        try {
            $pair = $auth->login($request->validated('email'), $request->validated('password'));
        } catch (AuthenticationException) {
            throw ValidationException::withMessages(['email' => 'No fue posible iniciar sesión con esas credenciales.']);
        }

        return $web->start($request, $pair);
    }
}
