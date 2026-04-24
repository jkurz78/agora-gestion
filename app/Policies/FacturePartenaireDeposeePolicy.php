<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleAssociation;
use App\Models\AssociationUser;
use App\Models\FacturePartenaireDeposee;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final class FacturePartenaireDeposeePolicy
{
    /**
     * Back-office: Admin or Comptable of the current tenant may treat (validate/reject) a deposited invoice.
     *
     * Guard: web ($user is \App\Models\User).
     *
     * @param  ?FacturePartenaireDeposee  $depot  Optional — when provided, also checks the depot belongs to the current tenant.
     */
    public function treat(?Authenticatable $user, ?FacturePartenaireDeposee $depot = null): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            return false;
        }

        // Defensive: if a depot instance is provided, verify it belongs to the current tenant.
        if ($depot !== null && (int) $depot->association_id !== (int) $tenantId) {
            return false;
        }

        $pivot = AssociationUser::where('user_id', (int) $user->id)
            ->where('association_id', (int) $tenantId)
            ->first();

        if ($pivot === null) {
            return false;
        }

        $role = $pivot->role instanceof RoleAssociation
            ? $pivot->role
            : RoleAssociation::from((string) $pivot->role);

        return in_array($role, [RoleAssociation::Admin, RoleAssociation::Comptable], true);
    }
}
