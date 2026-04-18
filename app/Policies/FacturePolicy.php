<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Facture;
use App\Models\User;

final class FacturePolicy
{
    public function viewAny(User $user): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canRead(Espace::Compta) ?? false;
    }

    public function view(User $user, Facture $facture): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canRead(Espace::Compta) ?? false;
    }

    public function create(User $user): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function update(User $user, Facture $facture): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function delete(User $user, Facture $facture): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }
}
