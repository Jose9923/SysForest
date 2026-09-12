<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindAuthenticatedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get('auth_session');
        // La selección institucional nunca se hereda de otra identidad o sesión autenticada.
        if ($session && $request->session()->get('auth_session_id') !== $session->id) {
            $request->session()->forget('active_organization_id');
            $request->session()->put('auth_session_id', $session->id);
        }

        return $next($request);
    }
}
