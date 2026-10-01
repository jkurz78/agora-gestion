<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;

trait LigneOrigineHelper
{
    /**
     * Fabrique la ligne d'origine d'un rattachement à l'exercice :
     *  - classe 7 : une ligne de produit, au crédit, sur une pièce de recette (journal de vente) ;
     *  - classe 6 : une ligne de charge, au débit, sur une pièce de dépense (journal d'achat).
     *
     * La pièce est datée du 2026-04-08, donc dans l'exercice 2025 (sept. 2025 → août 2026).
     * Chaque appel crée son propre compte via CompteFactory, au numéro unique :
     * on peut appeler la méthode plusieurs fois, pour la même classe, dans un même test.
     * Le tenant courant et l'utilisateur authentifié doivent être posés.
     *
     * Publique, contrairement à LigneDonHelper, pour qu'un helper global d'un fichier
     * de test puisse la rappeler : `$test->ligneOrigine(7)`.
     */
    public function ligneOrigine(
        int $classe,
        float $montant = 175.0,
        ?int $operationId = null,
        ?int $seance = null,
    ): TransactionLigne {
        $assocId = (int) TenantContext::currentId();

        $compte = ($classe === 7 ? Compte::factory()->recette() : Compte::factory()->depense())
            ->create(['association_id' => $assocId, 'actif' => true]);

        $transaction = Transaction::create([
            'association_id' => $assocId,
            'type' => $classe === 7 ? TypeTransaction::Recette : TypeTransaction::Depense,
            'date' => '2026-04-08',
            'libelle' => 'Pièce d’origine',
            'montant_total' => $montant,
            'saisi_par' => auth()->id(),
            'equilibree' => true,
            'type_ecriture' => 'normale',
            'journal' => $classe === 7 ? JournalComptable::Vente : JournalComptable::Achat,
        ]);

        return TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compte->id,
            'debit' => $classe === 6 ? $montant : 0,
            'credit' => $classe === 7 ? $montant : 0,
            'montant' => $montant,
            'operation_id' => $operationId,
            'seance' => $seance,
            'libelle' => 'Ligne d’origine',
        ]);
    }
}
