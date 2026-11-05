<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

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

        $continuity = app(\App\Services\Auth\SessionContinuityService::class);
        $continuity->assertRequestContext($request);
        $request->attributes->set('session_continuity', $continuity->clientConfiguration($request));

        try {
            return $next($request);
        } finally {
            $request->attributes->remove('session_continuity');
        }
    }
}
