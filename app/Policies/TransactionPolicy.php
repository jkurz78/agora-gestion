<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Models\Transaction;
use App\Models\User;

final class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }
}
