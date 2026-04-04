<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canAccessParametres();
    }

    public function view(User $user, User $model): bool
    {
        return $user->role->canAccessParametres();
    }

    public function create(User $user): bool
    {
        return $user->role->canAccessParametres();
    }

    public function update(User $user, User $model): bool
    {
        return $user->role->canAccessParametres();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->role->canAccessParametres() && $user->id !== $model->id;
    }
}
