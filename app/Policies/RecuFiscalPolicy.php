<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecuFiscalEmis;
use App\Models\User;

final class RecuFiscalPolicy
{
    public function download(User $user, RecuFiscalEmis $recu): bool
    {
        return $user->associations()
            ->where('association_id', $recu->association_id)
            ->exists();
    }

    public function annuler(User $user, RecuFiscalEmis $recu): bool
    {
        return $this->download($user, $recu);
    }
}
