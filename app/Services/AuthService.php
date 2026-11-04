<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\TokenPair;
use App\Repositories\AuthSessionRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    // Hash público de relleno: también se calcula bcrypt para cuentas inexistentes.
    private const string DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthSessionRepository $sessions,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenService $tokens,
        private readonly SecurityEventRepository $events,
        private readonly TenantContextService $tenants,
    ) {
    }

    /**
     * Verifica identidad bajo bloqueo y consulta sin crear sesión, refresh ni JWT.
     * @param Closure(User): array $callback
     */
    public function withVerifiedMobileUser(string $email, #[\SensitiveParameter] string $password, Closure $callback): array
    {
        $candidate = $this->users->findByEmail(Str::lower(trim($email)));
        if ($candidate === null) {
            Hash::check($password, self::DUMMY_HASH);
            $this->events->record('auth.login.failed', null, null);
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            $result = DB::transaction(function () use ($candidate, $email, $password, $callback): ?array {
                // El listado se resuelve dentro del mismo bloqueo que valida las credenciales actuales.
                $user = $this->users->findForUpdate((int) $candidate->id);
                $valid = Hash::check($password, $user->password);
                if (!$valid || !$user->active || $user->email_verified_at === null
                    || Str::lower($user->email) !== Str::lower(trim($email))) {
                    $this->events->record('auth.login.failed', null, null);

                    return null;
                }

                return $callback($user);
            });
        } catch (ModelNotFoundException) {
            $this->events->record('auth.login.failed', null, null);
            $result = null;
        }

        return $result ?? throw new AuthenticationException('No fue posible autenticar la solicitud.');
    }

    public function login(string $email, #[\SensitiveParameter] string $password, ?string $mobileOrganizationId = null): TokenPair
    {
        $candidate = $this->users->findByEmail(Str::lower(trim($email)));

        if ($candidate === null) {
            Hash::check($password, self::DUMMY_HASH);
            $this->events->record('auth.login.failed', null, null);
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            $pair = DB::transaction(function () use ($candidate, $email, $password, $mobileOrganizationId): ?TokenPair {
                // El bloqueo de usuario serializa login frente a cambio de contraseña/desactivación.
                $user = $this->users->findForUpdate((int) $candidate->id);
                $valid = Hash::check($password, $user->password);

                if (! $valid || ! $user->active || Str::lower($user->email) !== Str::lower(trim($email))) {
                    $this->events->record('auth.login.failed', null, null);

                    return null;
                }

                if (Hash::needsRehash($user->password)) {
                    $user = $this->users->update($user, ['password' => Hash::make($password)]);
                }

                if ($mobileOrganizationId !== null) {
                    // Solo una organización autorizada queda incorporada a la sesión móvil firmada.
                    $this->tenants->run($user, $mobileOrganizationId, fn () => $this->tenants->assertCurrentAccess($user, true));
                }

                $session = $this->sessions->create([
                    'user_id' => $user->id,
                    'client_type' => $mobileOrganizationId === null ? 'web' : 'mobile',
                    'organization_id' => $mobileOrganizationId,
                    'expires_at' => now()->addMinutes((int) config('security.session_ttl_minutes')),
                    'last_used_at' => now(),
                ]);
                $refresh = bin2hex(random_bytes(32));
                $this->refreshTokens->create([
                    'auth_session_id' => $session->id,
                    'token_hash' => hash('sha256', $refresh),
                    'expires_at' => $session->expires_at,
                ]);
                $pair = new TokenPair($this->tokens->issue($user, $session), $refresh, $session, $user);
                $this->events->record('auth.login.succeeded', $user, null, ['session_id' => $session->id]);

                return $pair;
            });
        } catch (ModelNotFoundException) {
            $this->events->record('auth.login.failed', null, null);
            $pair = null;
        }

        return $pair ?? throw new AuthenticationException('No fue posible autenticar la solicitud.');
    }
}
