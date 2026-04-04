<?php

use App\Livewire\RapportCompteResultat;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('se rend sans erreur', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertOk()
        ->assertSee('DEPENSES')
        ->assertSee('RECETTES')
        ->assertSee('Exporter');
});

it('affiche les catégories et sous-catégories', function () {
    $cat = Categorie::factory()->depense()->create(['nom' => 'Charges admin']);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Fournitures']);
    $d = Transaction::factory()->asDepense()->create(['date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 250.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('Charges admin')
        ->assertSee('Fournitures')
        ->assertSee('250,00');
});

it('affiche EXCÉDENT quand recettes > dépenses', function () {
    $catD = Categorie::factory()->depense()->create();
    $catR = Categorie::factory()->recette()->create();
    $scD = SousCategorie::factory()->create(['categorie_id' => $catD->id, 'nom' => 'Frais']);
    $scR = SousCategorie::factory()->create(['categorie_id' => $catR->id, 'nom' => 'Adhésions']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $scD->id, 'montant' => 100.00]);

    $r = Transaction::factory()->asRecette()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $r->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $r->id, 'sous_categorie_id' => $scR->id, 'montant' => 500.00]);

    Livewire::test(RapportCompteResultat::class)->assertSeeHtml('EXC&Eacute;DENT');
});

it('affiche DÉFICIT quand dépenses > recettes', function () {
    $cat = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Lourdes charges']);
    $d = Transaction::factory()->asDepense()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 5000.00]);

    Livewire::test(RapportCompteResultat::class)->assertSeeHtml('DÉFICIT');
});

it('affiche la barre de budget quand un budget existe', function () {
    $cat = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $d = Transaction::factory()->asDepense()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('80 %');
});

it("n'a pas de filtre opération", function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertDontSeeHtml('selectedOperationIds')
        ->assertOk();
});
