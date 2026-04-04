<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Models\Operation;
use App\Models\User;

final class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canRead(Espace::Gestion);
    }

    public function view(User $user, Operation $operation): bool
    {
        return $user->role->canRead(Espace::Gestion);
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Gestion);
    }

    public function update(User $user, Operation $operation): bool
    {
        return $user->role->canWrite(Espace::Gestion);
    }

    public function delete(User $user, Operation $operation): bool
    {
        return $user->role->canWrite(Espace::Gestion);
    }
}
