<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;

trait LigneDonHelper
{
    /**
     * Crée une TransactionLigne éligible à l'émission d'un reçu fiscal :
     *  - Tiers de type particulier avec adresse complète
     *  - Sous-catégorie portant l'usage Don
     *  - Transaction de type Recette, encaissée (statut_reglement = Recu)
     *
     * @param  array<string, mixed>  $tiersOverrides
     * @param  array<string, mixed>  $transactionOverrides
     * @param  array<string, mixed>  $ligneOverrides
     */
    protected function ligneDonValide(
        array $tiersOverrides = [],
        array $transactionOverrides = [],
        array $ligneOverrides = [],
    ): TransactionLigne {
        $tiers = Tiers::factory()->create(array_merge([
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Marie',
            'adresse_ligne1' => '12 rue des Lilas',
            'code_postal' => '75001',
            'ville' => 'Paris',
        ], $tiersOverrides));

        $sousCategorieDon = SousCategorie::query()
            ->whereHas('usages', fn ($q) => $q->where('usage', UsageComptable::Don->value))
            ->first()
            ?? SousCategorie::factory()->pourDons()->create();

        $transaction = Transaction::factory()->create(array_merge([
            'tiers_id' => $tiers->id,
            'type' => TypeTransaction::Recette,
            'date' => now()->subMonths(2),
            'statut_reglement' => StatutReglement::Recu,
            'mode_paiement' => ModePaiement::Cheque,
        ], $transactionOverrides));

        return TransactionLigne::factory()->create(array_merge([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $sousCategorieDon->id,
            'montant' => 150.00,
        ], $ligneOverrides));
    }
}
