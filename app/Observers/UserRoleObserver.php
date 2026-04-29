<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class UserRoleObserver
{
    public function saved(User $user): void
    {
        if ($user->wasChanged('role_systeme')) {
            Cache::forget('app.installed');
        }
    }

    public function deleted(User $user): void
    {
        Cache::forget('app.installed');
    }
}
