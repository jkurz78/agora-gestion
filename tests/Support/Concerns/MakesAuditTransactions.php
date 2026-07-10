<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Enums\StatutReglement;
use App\Models\Compte;
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
 * Le montant peut être négatif — aucune validation Eloquent ne l'interdit
 * (brèche du signe : les invariants de TransactionLigneObserver comparent à 0
 * avec `> 0` / `=== 0.0`, un montant négatif passe).
 *
 * Si la sous-catégorie porte un code_cerfa, la ligne est créée compte-first
 * (compte_id + debit/credit) — requis pour être vue par les lecteurs de rapports.
 * Sinon la ligne reste sans compte_id (suffisant pour les tests qui n'agrègent
 * que les transactions).
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

        $ligne = [
            'transaction_id' => $tx->id,
            'sous_categorie_id' => $sc->id,
            'montant' => $montant,
        ];

        if ($sc->code_cerfa !== null && $sc->code_cerfa !== '') {
            $compteVentilation = Compte::where('numero_pcg', $sc->code_cerfa)
                ->where('association_id', (int) $sc->association_id)
                ->firstOrFail();

            $estDepense = $type === 'depense';
            $ligne['compte_id'] = $compteVentilation->id;
            $ligne['debit'] = $estDepense ? $montant : 0.0;
            $ligne['credit'] = $estDepense ? 0.0 : $montant;
        }

        TransactionLigne::create($ligne);

        return $tx;
    }
}
