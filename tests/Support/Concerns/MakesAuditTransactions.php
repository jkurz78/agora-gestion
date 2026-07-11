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
}
