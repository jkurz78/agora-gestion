<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Compte;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * Policy for App\Models\Compte (Step 5 of plans/fondations-partie-double-slice1.md).
 *
 * Guard-rail: system accounts (est_systeme = TRUE) may never be updated or deleted
 * regardless of the user's role. This protects the seeded plan de comptes skeleton
 * (411 Clients, 401 Fournisseurs, 5112 Chèques à encaisser, 530 Caisse, and the
 * 512x bank accounts seeded in Step 4) from accidental mutation.
 *
 * For non-system accounts, write access is restricted to roles that canWrite(Espace::Compta)
 * — currently Admin and Comptable.
 *
 * Tenant guard-rail (audit 🟡) : refuse toute écriture sur un compte hors tenant
 * courant, même si l'instance provient d'un withoutGlobalScopes() ou d'un chemin
 * super-admin (mode support). Le TenantScope global filtre déjà en lecture, mais
 * cette vérification défense-en-profondeur ferme le cas où un Compte cross-tenant
 * atteindrait la policy. Fail-closed : si TenantContext n'est pas booté,
 * currentId() est null et la comparaison (int) échoue → refus.
 *
 * TODO step 9+ — refine role check when the écran "Paramètres > Comptes" lands.
 */
final class ComptePolicy
{
    public function update(User $user, Compte $compte): bool
    {
        if (! $this->appartientAuTenantCourant($compte)) {
            return false;
        }

        if ($compte->est_systeme) {
            return false;
        }

        // For non-system comptes, only Comptable + Admin can edit.
        return RoleAssociation::tryFrom($user->currentRole() ?? '')
            ?->canWrite(Espace::Compta) ?? false;
    }

    public function delete(User $user, Compte $compte): bool
    {
        if (! $this->appartientAuTenantCourant($compte)) {
            return false;
        }

        if ($compte->est_systeme) {
            return false;
        }

        return RoleAssociation::tryFrom($user->currentRole() ?? '')
            ?->canWrite(Espace::Compta) ?? false;
    }

    /**
     * Vrai uniquement si le compte appartient au tenant courant. Cast (int) des
     * deux côtés (MySQL prod retourne des strings) ; fail-closed si null.
     */
    private function appartientAuTenantCourant(Compte $compte): bool
    {
        $tenantCourant = TenantContext::currentId();

        return $tenantCourant !== null
            && (int) $compte->association_id === (int) $tenantCourant;
    }
}
