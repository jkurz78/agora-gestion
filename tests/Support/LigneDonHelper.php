<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;

trait LigneDonHelper
{
    /**
     * Crée une TransactionLigne éligible à l'émission d'un reçu fiscal :
     *  - Tiers de type particulier avec adresse complète
     *  - Compte portant l'usage Don
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

        $compteDon = Compte::query()
            ->forUsage(UsageComptable::Don)
            ->first()
            ?? Compte::factory()->pourDons()->create();

        $transaction = Transaction::factory()->create(array_merge([
            'tiers_id' => $tiers->id,
            'type' => TypeTransaction::Recette,
            'date' => now()->subMonths(2),
            'statut_reglement' => StatutReglement::Recu,
            'mode_paiement' => ModePaiement::Cheque,
        ], $transactionOverrides));

        $montant = $ligneOverrides['montant'] ?? 150.00;

        // RecuFiscalService (pas encore converti compte-first, Phase 2 à venir)
        // lit encore $ligne->sousCategorie : on renseigne donc le compte ET son
        // miroir sous_categorie_id (code_cerfa = numero_pcg) pour que les usages
        // (Don, AbandonCreance…) restent visibles côté legacy. Si l'appelant
        // override compte_id sans override sous_categorie_id, on re-dérive le
        // miroir à partir du compte final plutôt que de garder celui de $compteDon.
        $compteFinalId = $ligneOverrides['compte_id'] ?? $compteDon->id;
        $compteFinal = (int) $compteFinalId === (int) $compteDon->id
            ? $compteDon
            : Compte::find($compteFinalId);

        $sousCategorieFinaleId = $ligneOverrides['sous_categorie_id']
            ?? SousCategorie::where('code_cerfa', $compteFinal?->numero_pcg)->value('id');

        return TransactionLigne::factory()->create(array_merge([
            'transaction_id' => $transaction->id,
            'compte_id' => $compteFinalId,
            'sous_categorie_id' => $sousCategorieFinaleId,
            'debit' => 0,
            'credit' => $montant,
            'montant' => $montant,
        ], $ligneOverrides));
    }
}
