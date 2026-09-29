<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\TokenPair;
use App\Exceptions\SessionContinuityException;
use App\Models\AuthSession;
use App\Repositories\AuthSessionRepository;
use App\Repositories\RefreshTokenRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Conserva formularios abiertos al renovar cookies, sin convertir la sesión de navegación
 * en una credencial ni ampliar su frontera institucional. La rotación solo ocurre por POST con CSRF.
 */
class SessionContinuityService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly SessionService $sessions,
        private readonly RefreshTokenService $refresh,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly AuthSessionRepository $authSessions,
    ) {
    }

    /** Metadatos de presentación; nunca contiene JWT, refresh, identidad ni UUID de la familia. */
    public function clientConfiguration(Request $request): array
    {
        $session = $request->attributes->get('auth_session');
        if (!$session instanceof AuthSession) {
            return [];
        }

        try {
            $expiration = (int) $this->tokens->decode((string) $request->cookie(config('security.access_cookie')))['exp'];
        } catch (AuthenticationException) {
            // El access puede vencer entre el middleware de autenticación y el renderizado.
            $expiration = null;
        }

        return [
            'status_url' => route('auth.session.status'),
            'ensure_url' => route('auth.session.ensure'),
        ] + $this->describe($request, $session, $expiration);
    }

    /** La consulta es de solo lectura: incluso un refresh consumido no revoca ni rota por GET. */
    public function status(Request $request): array
    {
        // Una consulta de fondo no consume errores, input ni avisos destinados a la siguiente página.
        $request->session()->reflash();
        [$session, $expiration] = $this->authenticate($request);
        $this->assertRequestContext($request);

        return $this->describe($request, $session, $expiration);
    }

    /**
     * @return array{status: array<string, mixed>, pair: ?TokenPair}
     * @throws SessionContinuityException Si terminó la sesión o cambió el contexto del formulario.
     */
    public function ensure(Request $request): array
    {
        $request->session()->reflash();
        [$session, $expiration] = $this->authenticate($request, allowConsumedRefresh: true);
        $this->assertContext($request, $request->input('expected_context'));
        $this->assertRequestContext($request);
        $pair = null;

        if ($this->shouldRenew($session, $expiration)) {
            // La familia del refresh debe coincidir incluso cuando el access todavía es válido.
            $this->authenticateRefresh($request, $session->id, allowConsumed: true);
            try {
                $pair = $this->refresh->rotate((string) $request->cookie(config('security.refresh_cookie')));
            } catch (AuthenticationException) {
                $this->ended();
            }

            $session = $pair->session;
            $expiration = (int) $this->tokens->decode($pair->accessToken)['exp'];
        }

        // No invalidar ni regenerar CSRF: los formularios de esta misma familia siguen siendo válidos.
        // Login, recuperación manual y cambio de identidad conservan sus protecciones independientes.
        return ['status' => $this->describe($request, $session, $expiration), 'pair' => $pair];
    }

    /**
     * El HMAC identifica el contexto de la página y no autentica solicitudes por sí mismo.
     * Incluir la institución evita que otra pestaña cambie silenciosamente el destino de un formulario.
     */
    public function context(Request $request): string
    {
        return hash_hmac('sha256', json_encode([
            $request->session()->get('auth_session_id'),
            $request->session()->get('active_organization_id'),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Un formulario anterior se rechaza antes del Controller, aun si sus cookies ya fueron renovadas. */
    public function assertRequestContext(Request $request): void
    {
        if ($request->headers->has('X-Session-Context')) {
            $this->assertContext($request, $request->header('X-Session-Context'));
        }
        if ($request->exists('_session_context')) {
            $this->assertContext($request, $request->input('_session_context'));
        }
    }

    private function assertContext(Request $request, mixed $expected): void
    {
        if (!is_string($expected) || !hash_equals($this->context($request), $expected)) {
            throw new SessionContinuityException(
                'context_changed',
                'La cuenta o la organización cambió en otra pestaña. No se envió el formulario; revisa el contexto antes de continuar.',
                409,
            );
        }
    }

    /** @return array{AuthSession, ?int} */
    private function authenticate(Request $request, bool $allowConsumedRefresh = false): array
    {
        $bound = $request->session()->get('auth_session_id');
        if (!is_string($bound) || !Str::isUuid($bound)) {
            throw new SessionContinuityException(
                'context_missing',
                'La sesión del formulario terminó. Conserva los datos y abre el acceso en otra pestaña antes de continuar.',
                409,
            );
        }

        $claims = null;
        $access = $request->cookie(config('security.access_cookie'));
        if (is_string($access) && $access !== '') {
            try {
                $claims = $this->tokens->decode($access);
            } catch (AuthenticationException) {
                // El navegador puede conservar un access vencido o eliminarlo al expirar la cookie.
            }
        }

        if ($claims !== null) {
            $this->assertFamily($bound, $claims['sid']);
            try {
                $session = $this->sessions->authenticate($claims['sid'], (int) $claims['sub']);
            } catch (AuthenticationException) {
                $this->ended();
            }

            return [$session, (int) $claims['exp']];
        }

        return [$this->authenticateRefresh($request, $bound, $allowConsumedRefresh), null];
    }

    private function authenticateRefresh(Request $request, string $bound, bool $allowConsumed = false): AuthSession
    {
        $raw = $request->cookie(config('security.refresh_cookie'));
        $token = is_string($raw) && preg_match('/^[a-f0-9]{64}$/D', $raw) === 1
            ? $this->refreshTokens->findByHash(hash('sha256', $raw)) : null;
        if ($token === null || (!$allowConsumed && $token->consumed_at !== null)
            || $token->expires_at->lessThanOrEqualTo(now())) {
            $this->ended();
        }

        $this->assertFamily($bound, $token->auth_session_id);
        $candidate = $this->authSessions->find($token->auth_session_id);
        if ($candidate === null) {
            $this->ended();
        }

        try {
            return $this->sessions->authenticate($candidate->id, (int) $candidate->user_id);
        } catch (AuthenticationException) {
            $this->ended();
        }
    }

    private function assertFamily(string $bound, string $candidate): void
    {
        if (!hash_equals($bound, $candidate)) {
            throw new SessionContinuityException(
                'context_changed',
                'La cuenta cambió. No se envió el formulario; revisa la sesión antes de continuar.',
                409,
            );
        }
    }

    private function ended(): never
    {
        throw new SessionContinuityException(
            'ended',
            'La sesión terminó o fue revocada. Los datos del formulario permanecen en esta página.',
            401,
        );
    }

    private function renewBeforeSeconds(): int
    {
        return min(60, max(1, (int) floor((int) config('security.access_ttl_minutes') * 60 / 3)));
    }

    private function shouldRenew(AuthSession $session, ?int $expiration): bool
    {
        // Al alcanzar el vencimiento absoluto, rotar no aportaría tiempo y causaría un bucle.
        return $expiration === null || ($expiration < $session->expires_at->getTimestamp()
            && $expiration <= now()->getTimestamp() + $this->renewBeforeSeconds());
    }

    private function describe(Request $request, AuthSession $session, ?int $expiration): array
    {
        $now = now()->getTimestamp();
        $margin = $this->renewBeforeSeconds();

        return [
            'state' => $this->shouldRenew($session, $expiration) ? 'renewal_required' : 'ready',
            'server_time' => $now,
            'access_expires_at' => $expiration,
            'session_expires_at' => $session->expires_at->getTimestamp(),
            'renew_before_seconds' => $margin,
            'csrf_token' => $request->session()->token(),
            'context' => $this->context($request),
        ];
    }
}
