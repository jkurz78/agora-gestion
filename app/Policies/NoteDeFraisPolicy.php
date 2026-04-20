<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleAssociation;
use App\Enums\StatutNoteDeFrais;
use App\Models\AssociationUser;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class NoteDeFraisPolicy
{
    /**
     * Le "user" sur la garde tiers-portail est un Tiers (qui implémente Authenticatable).
     * On accepte Authenticatable|null pour satisfaire la signature que Laravel découvre,
     * puis on vérifie que c'est bien un Tiers.
     */
    public function view(?Authenticatable $user, NoteDeFrais $noteDeFrais): Response|bool
    {
        if (! $user instanceof Tiers) {
            return false;
        }

        return (int) $user->id === (int) $noteDeFrais->tiers_id;
    }

    public function update(?Authenticatable $user, NoteDeFrais $noteDeFrais): Response|bool
    {
        if (! $user instanceof Tiers) {
            return false;
        }

        if ((int) $user->id !== (int) $noteDeFrais->tiers_id) {
            return false;
        }

        // Brouillon et Soumise sont éditables — Rejetee/Validee/Payee sont read-only
        return in_array($noteDeFrais->statut, [
            StatutNoteDeFrais::Brouillon,
            StatutNoteDeFrais::Soumise,
        ], true);
    }

    /**
     * Back-office: Admin or Comptable of the current tenant may treat (validate/reject/pay) a NDF.
     *
     * Guard: web ($user is \App\Models\User).
     *
     * @param  ?NoteDeFrais  $noteDeFrais  Optional — when provided, also checks the NDF belongs to the current tenant.
     */
    public function treat(?Authenticatable $user, ?NoteDeFrais $noteDeFrais = null): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            return false;
        }

        // Defensive: if an NDF instance is provided, verify it belongs to the current tenant.
        if ($noteDeFrais !== null && (int) $noteDeFrais->association_id !== (int) $tenantId) {
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

    public function delete(?Authenticatable $user, NoteDeFrais $noteDeFrais): Response|bool
    {
        if (! $user instanceof Tiers) {
            return false;
        }

        if ((int) $user->id !== (int) $noteDeFrais->tiers_id) {
            return false;
        }

        // Brouillon et Soumise peuvent être supprimées — Rejetee/Validee/Payee non
        return in_array($noteDeFrais->statut, [
            StatutNoteDeFrais::Brouillon,
            StatutNoteDeFrais::Soumise,
        ], true);
    }
}
