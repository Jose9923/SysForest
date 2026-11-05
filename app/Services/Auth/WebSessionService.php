<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auth\AuthCookies;
use App\Auth\TokenPair;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebSessionService
{
    public function __construct(private AuthCookies $cookies, private EmailVerificationService $verification)
    {
    }

    /** La sesión web solo conserva CSRF y navegación; nunca autentica por sí misma. */
    public function start(Request $request, TokenPair $pair, bool $renewal = false): Response
    {
        $pendingInvitation = $request->session()->get('pending_invitation');
        $pendingVerification = $request->session()->get('pending_verification');
        $selected = $renewal && $request->session()->get('auth_session_id') === $pair->session->id
            ? $request->session()->get('active_organization_id') : null;
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('auth_session_id', $pair->session->id);
        if ($selected !== null) {
            $request->session()->put('active_organization_id', $selected);
        }

        if ($pendingInvitation) {
            $response = redirect()->route('invitations.preview', ['token' => $pendingInvitation]);
        } elseif ($pendingVerification) {
            $response = redirect()->route('verification.notice', ['token' => $pendingVerification]);
        } elseif (!$pair->user->hasVerifiedEmail()) {
            if (!$renewal) {
                $this->verification->send($pair->user);
            }
            $response = redirect()->route('verification.notice');
        } else {
            $response = redirect()->route('organizations.index');
        }

        return $this->cookies->attach($response, $pair);
    }

    public function end(Request $request, string $message = 'Sesión cerrada.'): Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->cookies->clear(redirect()->route('login')->with('status', $message));
    }
}
