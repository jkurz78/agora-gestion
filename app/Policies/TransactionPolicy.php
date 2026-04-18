<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Transaction;
use App\Models\User;

final class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canRead(Espace::Compta) ?? false;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canRead(Espace::Compta) ?? false;
    }

    public function create(User $user): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }
}
