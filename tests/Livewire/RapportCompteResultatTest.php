<?php

use App\Livewire\RapportCompteResultat;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

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

it('affiche les catégories et sous-catégories', function () {
    $cat = Categorie::factory()->depense()->create(['association_id' => $this->association->id, 'nom' => 'Charges admin']);
    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Fournitures']);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 250.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('Charges admin')
        ->assertSee('Fournitures')
        ->assertSee('250,00');
});

it('affiche le résultat avec couleur verte quand excédent', function () {
    $catD = Categorie::factory()->depense()->create(['association_id' => $this->association->id]);
    $catR = Categorie::factory()->recette()->create(['association_id' => $this->association->id]);
    $scD = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $catD->id, 'nom' => 'Frais']);
    $scR = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $catR->id, 'nom' => 'Adhésions']);

    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $scD->id, 'montant' => 100.00]);

    $r = Transaction::factory()->asRecette()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $r->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $r->id, 'sous_categorie_id' => $scR->id, 'montant' => 500.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('#2E7D32')
        ->assertSee('RÉSULTAT');
});

it('affiche le résultat avec couleur rouge quand déficit', function () {
    $cat = Categorie::factory()->depense()->create(['association_id' => $this->association->id]);
    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Lourdes charges']);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 5000.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSeeHtml('#B5453A')
        ->assertSee('RÉSULTAT');
});

it('affiche la barre de budget quand un budget existe', function () {
    $cat = Categorie::factory()->depense()->create(['association_id' => $this->association->id]);
    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['association_id' => $this->association->id, 'sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $d = Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('80 %');
});

it("n'a pas de filtre opération", function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertDontSeeHtml('selectedOperationIds')
        ->assertOk();
});
