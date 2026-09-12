<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Str;

class UserRepository
{
    public function find(int $id): User
    {
        return User::query()->findOrFail($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    /** Cuenta técnica explícita del entorno local, con contraseña aleatoria no publicada. */
    public function createLocalUser(string $email): User
    {
        return User::query()->create(['name' => 'Operador local de Fase 3', 'email' => $email, 'password' => Str::random(64)]);
    }
}
