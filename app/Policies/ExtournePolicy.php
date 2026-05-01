<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Transaction;
use App\Models\User;

final class ExtournePolicy
{
    /**
     * Authorize creation of an extourne for the given origin Transaction.
     *
     * Only Comptable and Admin (canWrite Espace::Compta) may extourne.
     * Super-admin in support read-only mode is naturally refused because
     * they have no role in the tenant pivot.
     */
    public function create(User $user, Transaction $origine): bool
    {
        return RoleAssociation::tryFrom($user->currentRole() ?? '')
            ?->canWrite(Espace::Compta) ?? false;
    }
}
