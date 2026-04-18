<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Tiers;
use App\Models\User;

final class TiersPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tiers $tiers): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        $role = RoleAssociation::tryFrom($user->currentRole() ?? '');

        return ($role?->canWrite(Espace::Compta) ?? false) || ($role?->canWrite(Espace::Gestion) ?? false);
    }

    public function update(User $user, Tiers $tiers): bool
    {
        $role = RoleAssociation::tryFrom($user->currentRole() ?? '');

        return ($role?->canWrite(Espace::Compta) ?? false) || ($role?->canWrite(Espace::Gestion) ?? false);
    }

    public function delete(User $user, Tiers $tiers): bool
    {
        $role = RoleAssociation::tryFrom($user->currentRole() ?? '');

        return ($role?->canWrite(Espace::Compta) ?? false) || ($role?->canWrite(Espace::Gestion) ?? false);
    }
}
