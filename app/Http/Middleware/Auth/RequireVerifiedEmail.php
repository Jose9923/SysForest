<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Debes verificar tu correo.'], 403)
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
