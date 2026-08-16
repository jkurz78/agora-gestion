<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;

trait MakesAuditTransactions
{
    /**
     * @param  array<string, mixed>  $overrides  Attributs à surcharger sur la transaction (ex: tiers_id, libelle…)
     */
    protected function makeAuditTransaction(
        string $type,
        float $montant,
        Compte $compteVentilation,
        CompteBancaire $compte,
        int $exercice,
        ?RapprochementBancaire $rapprochement = null,
        array $overrides = [],
    ): Transaction {
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

        $estDepense = $type === 'depense';

        TransactionLigne::create([
            'transaction_id' => $tx->id,
            'montant' => $montant,
            'compte_id' => $compteVentilation->id,
            'debit' => $estDepense ? $montant : 0.0,
            'credit' => $estDepense ? 0.0 : $montant,
        ]);

        return $tx;
    }

    /**
     * Comme makeAuditTransaction(), mais avec sa contrepartie de trésorerie.
     *
     * Les rapports de trésorerie se lisent sur le grand livre des comptes de
     * CLASSE 5 : une pièce sans ligne de trésorerie ne déplace aucun argent et
     * n'y figure pas, à juste titre. Les fixtures qui veulent représenter un
     * encaissement ou un décaissement réel doivent donc poser les deux jambes.
     *
     * Méthode distincte et non intégrée à makeAuditTransaction() : plusieurs
     * tests posent déjà leur propre contrepartie, et l'ajouter d'office
     * produirait une seconde ligne de trésorerie, donc une écriture
     * déséquilibrée.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeAuditTransactionTresorerie(
        string $type,
        float $montant,
        Compte $compteVentilation,
        CompteBancaire $compte,
        int $exercice,
        ?RapprochementBancaire $rapprochement = null,
        array $overrides = [],
    ): Transaction {
        $tx = $this->makeAuditTransaction(
            $type, $montant, $compteVentilation, $compte, $exercice, $rapprochement, $overrides
        );

        $compte512 = Compte::firstOrCreate(
            [
                'association_id' => (int) TenantContext::currentId(),
                'numero_pcg' => '512'.$compte->id,
            ],
            [
                'intitule' => 'Banque '.$compte->id,
                'classe' => 5,
                'actif' => true,
                'lettrable' => false,
                'est_systeme' => false,
                'pour_inscriptions' => false,
                'compte_bancaire_id' => $compte->id,
            ]
        );

        // Le signe est porté par debit/credit : une recette de -30 € crédite la
        // trésorerie de 30, exactement comme une sortie d'argent.
        $contribution = $type === 'depense' ? -$montant : $montant;

        TransactionLigne::create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte512->id,
            'debit' => $contribution > 0 ? $contribution : 0.0,
            'credit' => $contribution < 0 ? -$contribution : 0.0,
            'montant' => abs($montant),
        ]);

        return $tx;
    }
}
