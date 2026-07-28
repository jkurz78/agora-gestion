<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('détecte une divergence miroir↔ledger en --check (exit non nul)', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Divergence',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    // Corrompre le miroir (le dérivé serait EnMain pour un chèque en main).
    $t1->forceFill(['statut_reglement' => StatutReglement::Pointe->value])->save();

    $this->artisan('compta:reconcilier-statuts', ['--check' => true])
        ->assertExitCode(1);
});

it('corrige les divergences sans --check (exit 0, miroir resynchronisé)', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'À corriger',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $t1->forceFill(['statut_reglement' => StatutReglement::Pointe->value])->save();

    $this->artisan('compta:reconcilier-statuts')->assertExitCode(0);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

/**
 * Écriture technique : journal de banque ou OD, portant une ligne de tiers non
 * lettrée. Le resolver en dérive `EnAttente` — mais une T2/T4/OD n'a pas d'état
 * de règlement métier, son miroir ne doit donc pas être audité.
 */
function ecritureTechniqueDivergente(string $journal): Transaction
{
    $compte411 = Compte::where('numero_pcg', '411')->sole();

    $tx = Transaction::forceCreate([
        'association_id' => (int) TenantContext::currentId(),
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Écriture technique '.$journal,
        'montant_total' => '100.00',
        'journal' => $journal,
        'type_ecriture' => 'normale',
        'statut_reglement' => StatutReglement::Recu->value,
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'compte_id' => (int) $compte411->id,
        'montant' => 0,
        'debit' => '100.00',
        'credit' => 0,
        'lettrage_code' => null,   // non lettrée → le resolver dérive EnAttente
    ]);

    return $tx;
}

it('ignore les écritures techniques : le périmètre est métier (vente/achat)', function (string $journal) {
    ecritureTechniqueDivergente($journal);

    $this->artisan('compta:reconcilier-statuts', ['--check' => true])->assertExitCode(0);
})->with([JournalComptable::Banque->value, JournalComptable::Od->value]);

it('ne resynchronise pas les écritures techniques', function () {
    $technique = ecritureTechniqueDivergente(JournalComptable::Banque->value);

    $this->artisan('compta:reconcilier-statuts')->assertExitCode(0);

    expect($technique->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('audite toujours les écritures métier des journaux vente et achat', function (string $journal) {
    ecritureTechniqueDivergente($journal);

    $this->artisan('compta:reconcilier-statuts', ['--check' => true])->assertExitCode(1);
})->with([JournalComptable::Vente->value, JournalComptable::Achat->value]);

it('ne signale rien quand le miroir est aligné (exit 0)', function () {
    app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Aligné',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $this->artisan('compta:reconcilier-statuts', ['--check' => true])->assertExitCode(0);
});
