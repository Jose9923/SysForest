<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserRepository
{
    public function find(int $id): User
    {
        return User::query()->findOrFail($id);
    }

    public function findForUpdate(int $id): User
    {
        return User::query()->lockForUpdate()->findOrFail($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [strtolower(trim($email))])->first();
    }

    /** Los Services autorizan los atributos sensibles; nunca se pasa Request::all a este método. */
    public function create(array $attributes): User
    {
        $user = new User();
        return $this->update($user, $attributes);
    }

    public function update(User $user, array $attributes): User
    {
        $allowed = array_intersect_key($attributes, array_flip(['name', 'email', 'password', 'email_verified_at', 'active', 'remember_token']));
        if (isset($allowed['email'])) {
            $allowed['email'] = strtolower(trim($allowed['email']));
        }
        $user->forceFill($allowed)->save();
        return $user->refresh();
    }

    public function paginate(): LengthAwarePaginator
    {
        return User::query()->orderBy('name')->orderBy('id')->paginate(20);
    }

}
