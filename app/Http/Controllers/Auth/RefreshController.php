<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\RefreshRequest;
use App\Services\Auth\RefreshTokenService;
use App\Services\Auth\WebSessionService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Controller;

class RefreshController extends Controller
{
    public function create(): View
    {
        return view('auth.renew');
    }

    public function store(RefreshRequest $request, RefreshTokenService $tokens, WebSessionService $web): Response
    {
        try {
            $pair = $tokens->rotate((string) $request->cookie(config('security.refresh_cookie')));
        } catch (AuthenticationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return $web->end($request, 'La sesión terminó. Inicia sesión nuevamente.');
        }

        return $web->start($request, $pair, true);
    }
}
