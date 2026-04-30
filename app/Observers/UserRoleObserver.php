<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\RoleSysteme;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class UserRoleObserver
{
    public function created(User $user): void
    {
        if ($user->role_systeme === RoleSysteme::SuperAdmin) {
            Cache::forget('app.installed');
        }
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('role_systeme')) {
            Cache::forget('app.installed');
        }
    }

    public function deleted(User $user): void
    {
        if ($user->role_systeme === RoleSysteme::SuperAdmin) {
            Cache::forget('app.installed');
        }
    }
}
