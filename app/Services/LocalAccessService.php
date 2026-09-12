<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LocalAccessService
{
    public function __construct(
        private UserRepository $users,
        private OrganizationRepository $organizations,
        private OrganizationMembershipRepository $memberships,
    ) {
    }

    /** Entrada exclusiva de consola local; la creación y adhesión requieren flags explícitos. */
    public function issue(string $email, string $organizationCode, bool $createUser = false, bool $join = false): string
    {
        $this->ensureLocal();

        if (!app()->runningInConsole()) {
            throw new AuthorizationException('Este acceso solo puede emitirse desde Artisan.');
        }
        Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']])->validate();

        $payload = DB::transaction(function () use ($email, $organizationCode, $createUser, $join): array {
            $organization = Str::isUuid($organizationCode)
                ? $this->organizations->find($organizationCode) : $this->organizations->findByCode($organizationCode);
            $actor = $this->users->findByEmail($email);
            if ($actor === null && $createUser) {
                $actor = $this->users->createLocalUser($email);
            }
            if ($actor === null || !$organization->active) {
                throw new AuthorizationException('Se requiere una cuenta existente y una organización activa.');
            }
            if ($join) {
                $this->memberships->upsert(
                    (int) $actor->id,
                    $organization->id,
                    ['active' => true, 'joined_at' => now(), 'left_at' => null]
                );
            }
            if ($this->memberships->activeFor((int) $actor->id, $organization->id) === null) {
                throw new AuthorizationException('La cuenta no tiene membresía activa en esta organización.');
            }

            return ['actor_id' => (int) $actor->id, 'organization_id' => $organization->id];
        });

        $token = bin2hex(random_bytes(32));
        Cache::put($this->cacheKey($token), $payload, now()->addMinutes(10));

        return URL::temporarySignedRoute('phase3.local.consume', now()->addMinutes(10), ['token' => $token]);
    }

    /** Token de un solo uso, guardado bajo hash y consumido con bloqueo en Redis. */
    public function consume(string $token): array
    {
        $this->ensureLocal();
        $key = $this->cacheKey($token);
        $payload = Cache::lock($key.':lock', 10)->block(3, fn () => Cache::pull($key));

        if (!is_array($payload)) {
            throw new AuthorizationException('El enlace caducó o ya fue utilizado.');
        }
        $actor = $this->users->find((int) $payload['actor_id']);
        if ($this->memberships->activeFor((int) $actor->id, $payload['organization_id']) === null) {
            throw new AuthorizationException('La membresía ya no permite este acceso.');
        }

        return ['actor' => $actor, 'organization_id' => $payload['organization_id'], 'expires_at' => time() + 1800];
    }

    private function ensureLocal(): void
    {
        if (!app()->environment('local')) {
            throw new AuthorizationException('El acceso técnico está deshabilitado fuera del entorno local.');
        }
    }

    private function cacheKey(string $token): string
    {
        return 'phase3:local-access:'.hash('sha256', $token);
    }
}
