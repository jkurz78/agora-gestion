<?php

use App\Livewire\RapportCompteResultat;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
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
        ->assertSee('DÉPENSES')
        ->assertSee('RECETTES')
        ->assertSee('Exporter CSV');
});

it('affiche les catégories et sous-catégories', function () {
    $cat = Categorie::factory()->depense()->create(['nom' => 'Charges admin']);
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Fournitures']);
    $d   = Depense::factory()->create(['date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 250.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('Charges admin')
        ->assertSee('Fournitures')
        ->assertSee('250,00');
});

it('affiche EXCÉDENT quand recettes > dépenses', function () {
    $catD = Categorie::factory()->depense()->create();
    $catR = Categorie::factory()->recette()->create();
    $scD  = SousCategorie::factory()->create(['categorie_id' => $catD->id, 'nom' => 'Frais']);
    $scR  = SousCategorie::factory()->create(['categorie_id' => $catR->id, 'nom' => 'Adhésions']);

    $d = Depense::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $scD->id, 'montant' => 100.00]);

    $r = Recette::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $r->lignes()->forceDelete();
    RecetteLigne::factory()->create(['recette_id' => $r->id, 'sous_categorie_id' => $scR->id, 'montant' => 500.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('EXCÉDENT');
});

it('affiche DÉFICIT quand dépenses > recettes', function () {
    $cat = Categorie::factory()->depense()->create();
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Lourdes charges']);
    $d   = Depense::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 5000.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('DÉFICIT');
});

it('affiche la barre de budget quand un budget existe', function () {
    $cat = Categorie::factory()->depense()->create();
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $d = Depense::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('80 %');
});

it("n'a pas de filtre opération", function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertDontSeeHtml('selectedOperationIds')
        ->assertOk();
});
