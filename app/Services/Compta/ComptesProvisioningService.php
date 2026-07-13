<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;

/**
 * Provisionne les comptes bancaires et système nécessaires à la partie double.
 *
 * Les comptes de gestion 6/7 sont désormais créés directement par le plan
 * comptable du tenant. Ce service ne reconstruit donc jamais ces comptes à
 * partir d'une source de transition.
 *
 *  - `DatabaseSeeder` l'appelle après avoir créé association / comptes bancaires
 *    et plan comptable.
 *  - Le wizard d'onboarding l'appelle à la finalisation → tout nouveau tenant
 *    obtient ses comptes système (411/401/5112) et bancaires (512X).
 *
 * Chaque seed est idempotent (INSERT IGNORE / NOT EXISTS) : un rejeu est un
 * no-op pour les comptes déjà présents.
 */
final class ComptesProvisioningService
{
    public function provisionAll(): void
    {
        // Comptes bancaires physiques (512X) depuis comptes_bancaires.
        BancairesSeeder::seed();

        // Comptes système (411/401/5112 + 530 conditionnel).
        SystemeSeeder::seed();
    }
}
