<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Models\Facture;
use App\Models\User;

final class FacturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function view(User $user, Facture $facture): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function update(User $user, Facture $facture): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function delete(User $user, Facture $facture): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }
}
