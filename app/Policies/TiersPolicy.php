<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
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
        return $user->role->canWrite(Espace::Compta) || $user->role->canWrite(Espace::Gestion);
    }

    public function update(User $user, Tiers $tiers): bool
    {
        return $user->role->canWrite(Espace::Compta) || $user->role->canWrite(Espace::Gestion);
    }

    public function delete(User $user, Tiers $tiers): bool
    {
        return $user->role->canWrite(Espace::Compta) || $user->role->canWrite(Espace::Gestion);
    }
}
