<?php

use App\Livewire\RecetteForm;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->categorie = Categorie::factory()->recette()->create();
    $this->sousCategorie = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
    ]);
    $this->compte = CompteBancaire::factory()->create();

    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders the form component', function () {
    Livewire::test(RecetteForm::class)
        ->assertOk()
        ->assertSee('Nouvelle recette');
});

it('can add a ligne', function () {
    Livewire::test(RecetteForm::class)
        ->set('showForm', true)
        ->call('addLigne')
        ->assertCount('lignes', 1)
        ->call('addLigne')
        ->assertCount('lignes', 2);
});

it('can remove a ligne', function () {
    Livewire::test(RecetteForm::class)
        ->set('showForm', true)
        ->call('addLigne')
        ->call('addLigne')
        ->assertCount('lignes', 2)
        ->call('removeLigne', 0)
        ->assertCount('lignes', 1);
});

it('validates required fields', function () {
    Livewire::test(RecetteForm::class)
        ->set('showForm', true)
        ->call('save')
        ->assertHasErrors(['date', 'libelle', 'mode_paiement', 'lignes']);
});

it('computes montant_total from lignes sum', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(RecetteForm::class)
        ->set('lignes', [
            ['sous_categorie_id' => '', 'operation_id' => '', 'seance' => '', 'montant' => '30.00', 'notes' => ''],
            ['sous_categorie_id' => '', 'operation_id' => '', 'seance' => '', 'montant' => '20.50', 'notes' => ''],
        ])
        ->assertSet('montantTotal', 50.50);
});

it('can save a new recette', function () {
    Livewire::test(RecetteForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('libelle', 'Cotisation membre')
        ->set('mode_paiement', 'cb')
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [
            [
                'sous_categorie_id' => (string) $this->sousCategorie->id,
                'operation_id' => '',
                'seance' => '',
                'montant' => '100.00',
                'notes' => 'Cotisation annuelle',
            ],
            [
                'sous_categorie_id' => (string) $this->sousCategorie->id,
                'operation_id' => '',
                'seance' => '',
                'montant' => '50.00',
                'notes' => '',
            ],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('recette-saved');

    $this->assertDatabaseHas('recettes', [
        'libelle' => 'Cotisation membre',
        'montant_total' => '150.00',
        'mode_paiement' => 'cb',
        'saisi_par' => $this->user->id,
    ]);

    $recette = Recette::where('libelle', 'Cotisation membre')->first();
    expect($recette->lignes)->toHaveCount(2);
});

it('can load existing recette for editing', function () {
    $recette = Recette::factory()->create([
        'date' => '2025-11-10',
        'libelle' => 'Recette existante',
        'montant_total' => 200.00,
        'mode_paiement' => 'cheque',
        'saisi_par' => $this->user->id,
        'compte_id' => $this->compte->id,
    ]);

    // Remove factory-auto-created lignes and make our own
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'montant' => 200.00,
    ]);

    Livewire::test(RecetteForm::class)
        ->call('edit', $recette->id)
        ->assertSet('recetteId', $recette->id)
        ->assertSet('libelle', 'Recette existante')
        ->assertSet('mode_paiement', 'cheque')
        ->assertSet('showForm', true)
        ->assertCount('lignes', 1);
});

it('affiche le numero_piece en mode édition', function () {
    $recette = Recette::factory()->create([
        'date' => '2025-12-01',
        'numero_piece' => '2025-2026:00007',
        'compte_id' => $this->compte->id,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteForm::class)
        ->call('edit', $recette->id)
        ->assertSee('2025-2026:00007');
});

it('can update a recette', function () {
    $recette = Recette::factory()->create([
        'date' => '2025-10-15',
        'libelle' => 'Ancienne recette',
        'montant_total' => 100.00,
        'mode_paiement' => 'especes',
        'saisi_par' => $this->user->id,
        'compte_id' => $this->compte->id,
    ]);

    // Remove factory-auto-created lignes and make our own
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'montant' => 100.00,
    ]);

    Livewire::test(RecetteForm::class)
        ->call('edit', $recette->id)
        ->set('libelle', 'Recette mise à jour')
        ->set('lignes', [
            [
                'sous_categorie_id' => (string) $this->sousCategorie->id,
                'operation_id' => '',
                'seance' => '',
                'montant' => '75.00',
                'notes' => '',
            ],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('recette-saved');

    $this->assertDatabaseHas('recettes', [
        'id' => $recette->id,
        'libelle' => 'Recette mise à jour',
        'montant_total' => '75.00',
    ]);

    // Old lignes replaced
    expect(RecetteLigne::where('recette_id', $recette->id)->count())->toBe(1);
});

it('rejette une date avant le début de l\'exercice', function () {
    // exercice 2025 : 2025-09-01 → 2026-08-31
    Livewire::test(RecetteForm::class)
        ->call('showNewForm')
        ->set('date', '2025-08-31')
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [['sous_categorie_id' => $this->sousCategorie->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasErrors(['date']);
});

it('rejette une date après la fin de l\'exercice', function () {
    Livewire::test(RecetteForm::class)
        ->call('showNewForm')
        ->set('date', '2026-09-01')
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [['sous_categorie_id' => $this->sousCategorie->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasErrors(['date']);
});

it('accepte une date dans l\'exercice', function () {
    Livewire::test(RecetteForm::class)
        ->call('showNewForm')
        ->set('date', '2025-10-01')
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [['sous_categorie_id' => $this->sousCategorie->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasNoErrors(['date']);
});
