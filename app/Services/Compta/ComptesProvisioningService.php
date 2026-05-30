<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Services\Compta\Migrations\AuditGuard;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Provisionne la table `comptes` à partir des données source
 * (`sous_categories`, `comptes_bancaires`, `transactions`).
 *
 * Les migrations 2026_05_20_* exécutent ces mêmes seeds, mais au moment des
 * migrations les tables source sont vides en `migrate:fresh --seed` (peuplées
 * ensuite par DatabaseSeeder) et inexistantes pour un tenant onboardé après le
 * déploiement. Ce service rejoue les seeds une fois les données présentes :
 *
 *  - `DatabaseSeeder` l'appelle après avoir créé association / comptes bancaires
 *    / sous-catégories → la table `comptes` est correctement peuplée après
 *    `migrate:fresh --seed`.
 *  - Le wizard d'onboarding l'appelle à la finalisation → tout nouveau tenant
 *    obtient ses comptes système (411/401/5112), bancaires (512X) et de gestion.
 *
 * Chaque seed est idempotent (INSERT IGNORE / NOT EXISTS) : un rejeu est un
 * no-op pour les comptes déjà présents.
 */
final class ComptesProvisioningService
{
    public function provisionAll(): void
    {
        // 1. Comptes de gestion (classes 6/7) depuis les sous-catégories.
        DB::statement(AuditGuard::seedFromSousCategoriesSql());

        // 2. Comptes bancaires physiques (512X) depuis comptes_bancaires.
        BancairesSeeder::seed();

        // 3. Comptes système (411/401/5112 + 530 conditionnel).
        SystemeSeeder::seed();
    }
}
