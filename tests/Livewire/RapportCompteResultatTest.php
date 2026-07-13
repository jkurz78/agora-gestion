<?php

use App\Livewire\RapportCompteResultat;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Ligne de ventilation compte-first : le compte est passé directement,
 * debit/credit posés selon le type de la transaction (dépense: débit, recette: crédit).
 */
function crTestLigne(Transaction $tx, Compte $compte, float $montant): TransactionLigne
{
    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => $montant,
        'debit' => $estDepense ? $montant : 0.0,
        'credit' => $estDepense ? 0.0 : $montant,
    ]);
}

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('se rend sans erreur', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertOk()
        ->assertSee('DEPENSES')
        ->assertSee('RECETTES')
        ->assertSee('Exporter');
});

it('affiche les familles et comptes', function () {
    // DC-4 : le regroupement de 1er niveau est désormais la famille (préfixe 2 chiffres
    // du numero_pcg) et non plus la catégorie. code_cerfa déclenche la matérialisation
    // Compte (intitulé = nom de le compte) + Famille (nom = code, fallback
    // CompteObserver) — d'où le libellé "60 — 60".
    $compte = Compte::factory()->numero('606')->create(['association_id' => $this->association->id, 'intitule' => 'Fournitures']);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crTestLigne($d, $compte, 250.00);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('60 — 60')
        ->assertSee('Fournitures')
        ->assertSee('250,00');
});

it('affiche le résultat avec couleur verte quand excédent', function () {
    $compteD = Compte::factory()->numero('616')->create(['association_id' => $this->association->id, 'intitule' => 'Frais']);
    $compteR = Compte::factory()->numero('716')->create(['association_id' => $this->association->id, 'intitule' => 'Adhésions']);

    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crTestLigne($d, $compteD, 100.00);

    $r = Transaction::factory()->asRecette()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $r->lignes()->forceDelete();
    crTestLigne($r, $compteR, 500.00);

    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('#2E7D32')
        ->assertSee('RÉSULTAT');
});

it('affiche le résultat avec couleur rouge quand déficit', function () {
    $compte = Compte::factory()->numero('626')->create(['association_id' => $this->association->id, 'intitule' => 'Lourdes charges']);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crTestLigne($d, $compte, 5000.00);

    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('#B5453A')
        ->assertSee('RÉSULTAT');
});

it('affiche la barre de budget quand un budget existe', function () {
    $compte = Compte::factory()->numero('636')->create(['association_id' => $this->association->id, 'intitule' => 'Salle']);
    BudgetLine::factory()->create(['association_id' => $this->association->id, 'compte_id' => $compte->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crTestLigne($d, $compte, 800.00);

    Livewire::test(RapportCompteResultat::class)->assertSee('80 %');
});

it('barre budget recette au-dessus de l\'objectif → verte (et non rouge)', function () {
    // Recette à 120 % de son budget → la barre doit être VERTE (plus que prévu = bien).
    $compteR = Compte::factory()->numero('746')->create(['association_id' => $this->association->id, 'intitule' => 'Cotisations']);
    BudgetLine::factory()->create(['association_id' => $this->association->id, 'compte_id' => $compteR->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $r = Transaction::factory()->asRecette()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $r->lignes()->forceDelete();
    crTestLigne($r, $compteR, 1200.00);

    // Grosse dépense sans budget → résultat déficitaire (rouge) : le SEUL vert possible est la barre recette.
    $compteD = Compte::factory()->numero('646')->create(['association_id' => $this->association->id, 'intitule' => 'Frais']);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crTestLigne($d, $compteD, 5000.00);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('120 %')
        ->assertSeeHtml('background:#2E7D32');
});

it("n'a pas de filtre opération", function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertDontSeeHtml('selectedOperationIds')
        ->assertOk();
});

it('a les deux toggles ON par défaut', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertSet('compareN1', true)
        ->assertSet('compareBudget', true);
});

it('propage l\'état des toggles dans exportUrl', function () {
    $url = Livewire::test(RapportCompteResultat::class)
        ->set('compareN1', false)
        ->set('compareBudget', false)
        ->instance()
        ->exportUrl('xlsx');

    expect($url)->toContain('n1=0')->toContain('budget=0');
});

it('masque la colonne N-1 quand compareN1 est false', function () {
    $compte = Compte::factory()->numero('656')->create(['association_id' => $this->association->id, 'intitule' => 'Frais']);
    // Une dépense datée dans l'exercice N-1 (2024-2025) pour produire un montant_n1 distinct.
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2024-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crTestLigne($d, $compte, 777.00);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('777,00')              // visible par défaut (colonne N-1 affichée)
        ->set('compareN1', false)
        ->assertDontSee('777,00');         // masquée
});

it('masque budget/écart/barre quand compareBudget est false', function () {
    $compte = Compte::factory()->numero('666')->create(['association_id' => $this->association->id, 'intitule' => 'Salle']);
    BudgetLine::factory()->create(['association_id' => $this->association->id, 'compte_id' => $compte->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    crTestLigne($d, $compte, 800.00);

    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('budget-bar-fill')   // barre visible par défaut
        ->set('compareBudget', false)
        ->assertDontSeeHtml('budget-bar-fill')
        ->assertDontSee('80 %');
});

it('affiche les deux switches de comparaison', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('wire:model.live="compareN1"')
        ->assertSeeHtml('wire:model.live="compareBudget"');
});
