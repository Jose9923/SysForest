<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AccountActionTokenRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/** Clave pública del piloto, solicitada por el usuario; nunca participa en el cambio de contraseña web. */
class DevelopmentPasswordService
{
    public const DEFAULT_PASSWORD = 'password';

    public function __construct(
        private UserRepository $users,
        private AccountActionTokenRepository $tokens,
        private SessionService $sessions,
        private SecurityEventRepository $events,
    ) {
    }

    /**
     * Incluye cuentas locales ajenas a los cuatro perfiles iniciales, sin cambiar identidad ni permisos.
     * Los bloqueos mantienen el mismo orden usuario → sesiones que los flujos de autenticación.
     *
     * @return array{updated: int, unchanged: int}
     * @throws LogicException Si se invoca fuera de consola local o testing.
     */
    public function resetAll(): array
    {
        if (!app()->runningInConsole() || !app()->environment(['local', 'testing'])) {
            throw new LogicException('La clave predeterminada solo puede aplicarse desde consola en local o testing.');
        }

        return DB::transaction(function (): array {
            $result = ['updated' => 0, 'unchanged' => 0];
            foreach ($this->users->allForUpdate() as $user) {
                if (Hash::check(self::DEFAULT_PASSWORD, $user->password)) {
                    $result['unchanged']++;

                    continue;
                }

                // User convierte la clave a hash; ni el valor ni el hash forman parte de la auditoría.
                $user = $this->users->update($user, [
                    'password' => self::DEFAULT_PASSWORD,
                    'remember_token' => Str::random(60),
                ]);
                $this->tokens->consumePending($user, 'password_reset');
                $this->sessions->revokeAll($user, 'development_password_reset');
                $this->events->record(
                    'password.development_default',
                    null,
                    null,
                    ['target_user_id' => (int) $user->id],
                    'user',
                    (string) $user->id,
                    [],
                    []
                );
                $result['updated']++;
            }

            return $result;
        }, 3);
    }
}
