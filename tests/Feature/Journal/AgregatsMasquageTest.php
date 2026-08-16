<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Tenant\TenantContext;

/**
 * Règle : dans le flux de trésorerie, chaque euro est compté UNE FOIS.
 *
 * Cette règle n'a pas changé ; son moyen, si. Ce fichier affirmait auparavant
 * que le flux devait EXCLURE le journal `banque`. C'était la parade correcte
 * de l'époque : le rapport sommait `transactions.montant_total`, si bien qu'une
 * recette (T1, journal `vente`) et son encaissement (T2, journal `banque`)
 * comptaient deux fois la même somme. Exclure la T2 rétablissait le compte —
 * au prix de mesurer des engagements plutôt que de la trésorerie.
 *
 * Le rapport lit désormais le grand livre des comptes de classe 5. Le double
 * comptage y est structurellement impossible : sur la paire T1/T2, une seule
 * touche la trésorerie. C'est donc l'inverse qu'il faut vérifier — le flux
 * compte la T2, celle où l'argent bouge, et ignore la T1, qui n'est qu'un
 * engagement. L'invariant « une fois » est préservé, on l'observe juste de
 * l'autre côté.
 *
 * Décision produit du 2026-08-14 : la trésorerie est le solde des comptes de
 * classe 5 — 51 (banques et valeurs à l'encaissement) et 53 (caisse).
 */
beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->compte512 = Compte::factory()->create([
        'association_id' => (int) $this->association->id,
        'numero_pcg' => '5121',
        'intitule' => 'Compte courant',
        'classe' => 5,
    ]);
    $this->compte706 = Compte::factory()->create([
        'association_id' => (int) $this->association->id,
        'numero_pcg' => '706',
        'classe' => 7,
    ]);
    $this->compte411 = Compte::factory()->create([
        'association_id' => (int) $this->association->id,
        'numero_pcg' => '411',
        'classe' => 4,
    ]);

    /** Crée une transaction et ses lignes. $lignes = [[compte, debit, credit], …] */
    $this->ecriture = function (JournalComptable $journal, string $date, float $montant, array $lignes): Transaction {
        $tx = Transaction::factory()->asRecette()->create([
            'association_id' => (int) TenantContext::currentId(),
            'journal' => $journal,
            'montant_total' => $montant,
            'date' => $date,
        ]);

        foreach ($lignes as [$compte, $debit, $credit]) {
            TransactionLigne::factory()->create([
                'transaction_id' => $tx->id,
                'compte_id' => $compte->id,
                'debit' => $debit,
                'credit' => $credit,
                'montant' => $montant,
            ]);
        }

        return $tx;
    };
});

it('compte l’encaissement une seule fois, et non l’engagement qui le précède', function () {
    // T1 — la créance : 411 au débit, 706 au crédit. Aucun euro n'a bougé.
    ($this->ecriture)(JournalComptable::Vente, '2025-10-01', 100.0, [
        [$this->compte411, 100.0, 0.0],
        [$this->compte706, 0.0, 100.0],
    ]);

    // T2 — l'encaissement : 512 au débit, 411 au crédit. L'argent arrive.
    ($this->ecriture)(JournalComptable::Banque, '2025-10-02', 100.0, [
        [$this->compte512, 100.0, 0.0],
        [$this->compte411, 0.0, 100.0],
    ]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    // 100 encaissés, comptés une fois — pas 200, pas 0.
    expect($flux['synthese']['total_recettes'])->toBe(100.0)
        ->and($flux['synthese']['variation'])->toBe(100.0);
});

it('ne compte pas une créance tant qu’elle n’est pas encaissée', function () {
    ($this->ecriture)(JournalComptable::Vente, '2025-10-01', 100.0, [
        [$this->compte411, 100.0, 0.0],
        [$this->compte706, 0.0, 100.0],
    ]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    expect($flux['synthese']['total_recettes'])->toBe(0.0)
        ->and($flux['synthese']['variation'])->toBe(0.0);
});

it('ventile l’encaissement sur son mois, pas sur celui de l’engagement', function () {
    // Créance en octobre, encaissée en novembre : le flux doit voir novembre.
    ($this->ecriture)(JournalComptable::Vente, '2025-10-01', 100.0, [
        [$this->compte411, 100.0, 0.0],
        [$this->compte706, 0.0, 100.0],
    ]);
    ($this->ecriture)(JournalComptable::Banque, '2025-11-15', 100.0, [
        [$this->compte512, 100.0, 0.0],
        [$this->compte411, 0.0, 100.0],
    ]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    // Index 0 = septembre, 1 = octobre, 2 = novembre.
    expect($flux['mensuel'][1]['recettes'])->toBe(0.0, 'Octobre ne porte qu’un engagement')
        ->and($flux['mensuel'][2]['recettes'])->toBe(100.0, 'Novembre porte l’encaissement');
});
