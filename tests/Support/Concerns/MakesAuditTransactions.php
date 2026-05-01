<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Enums\StatutReglement;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * Fournit `makeAuditTransaction()` aux fichiers de test d'audit signe négatif.
 *
 * Crée une transaction (recette ou dépense) avec une seule ligne sur la
 * sous-catégorie donnée, dans l'exercice donné.
 * Le montant peut être négatif — aucune validation Eloquent ne l'interdit.
 */
trait MakesAuditTransactions
{
    /**
     * @param  array<string, mixed>  $overrides  Attributs à surcharger sur la transaction (ex: tiers_id, libelle…)
     */
    protected function makeAuditTransaction(
        string $type,
        float $montant,
        SousCategorie $sc,
        CompteBancaire $compte,
        int $exercice,
        ?RapprochementBancaire $rapprochement = null,
        array $overrides = [],
    ): Transaction {
        // L'exercice 2025 court du 2025-09-01 au 2026-08-31
        $date = "{$exercice}-10-15";

        $tx = Transaction::create(array_merge([
            'association_id' => TenantContext::currentId(),
            'type' => $type,
            'date' => $date,
            'libelle' => "Audit test {$type} {$montant}",
            'montant_total' => $montant,
            'mode_paiement' => 'virement',
            'compte_id' => $compte->id,
            'statut_reglement' => StatutReglement::EnAttente->value,
            'saisi_par' => User::factory()->create()->id,
            'rapprochement_id' => $rapprochement?->id,
        ], $overrides));

        TransactionLigne::create([
            'transaction_id' => $tx->id,
            'sous_categorie_id' => $sc->id,
            'montant' => $montant,
        ]);

        return $tx;
    }
}
