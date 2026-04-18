<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Operation;
use App\Models\User;

final class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canRead(Espace::Gestion) ?? false;
    }

    public function view(User $user, Operation $operation): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canRead(Espace::Gestion) ?? false;
    }

    public function create(User $user): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    public function update(User $user, Operation $operation): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    public function delete(User $user, Operation $operation): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }
}
