<?php

use App\Livewire\DepenseForm;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\SousCategorie;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->categorie = Categorie::factory()->depense()->create();
    $this->sousCategorie = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
    ]);
    $this->compte = CompteBancaire::factory()->create();
});

it('renders the form component', function () {
    Livewire::test(DepenseForm::class)
        ->assertOk()
        ->assertSee('Nouvelle dépense');
});

it('can add a ligne', function () {
    Livewire::test(DepenseForm::class)
        ->set('showForm', true)
        ->call('addLigne')
        ->assertCount('lignes', 1)
        ->call('addLigne')
        ->assertCount('lignes', 2);
});

it('can remove a ligne', function () {
    Livewire::test(DepenseForm::class)
        ->set('showForm', true)
        ->call('addLigne')
        ->call('addLigne')
        ->assertCount('lignes', 2)
        ->call('removeLigne', 0)
        ->assertCount('lignes', 1);
});

it('validates required fields', function () {
    Livewire::test(DepenseForm::class)
        ->set('showForm', true)
        ->call('save')
        ->assertHasErrors(['date', 'libelle', 'mode_paiement', 'lignes']);
});

it('computes montant_total from lignes sum', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(DepenseForm::class)
        ->set('lignes', [
            ['sous_categorie_id' => '', 'operation_id' => '', 'seance' => '', 'montant' => '30.00', 'notes' => ''],
            ['sous_categorie_id' => '', 'operation_id' => '', 'seance' => '', 'montant' => '20.50', 'notes' => ''],
        ])
        ->assertSet('montantTotal', 50.50);
});

it('can save a new depense', function () {
    Livewire::test(DepenseForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('libelle', 'Achat fournitures')
        ->set('mode_paiement', 'cb')
        ->set('tiers', 'Fournisseur XYZ')
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [
            [
                'sous_categorie_id' => (string) $this->sousCategorie->id,
                'operation_id' => '',
                'seance' => '',
                'montant' => '100.00',
                'notes' => 'Papeterie',
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
        ->assertDispatched('depense-saved');

    $this->assertDatabaseHas('depenses', [
        'libelle' => 'Achat fournitures',
        'montant_total' => '150.00',
        'mode_paiement' => 'cb',
        'tiers' => 'Fournisseur XYZ',
        'saisi_par' => $this->user->id,
    ]);

    $depense = Depense::where('libelle', 'Achat fournitures')->first();
    expect($depense->lignes)->toHaveCount(2);
});

it('can load existing depense for editing', function () {
    $depense = Depense::factory()->create([
        'libelle' => 'Dépense existante',
        'montant_total' => 200.00,
        'mode_paiement' => 'cheque',
        'saisi_par' => $this->user->id,
        'compte_id' => $this->compte->id,
    ]);

    // Remove factory-auto-created lignes and make our own
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'montant' => 200.00,
    ]);

    Livewire::test(DepenseForm::class)
        ->call('edit', $depense->id)
        ->assertSet('depenseId', $depense->id)
        ->assertSet('libelle', 'Dépense existante')
        ->assertSet('mode_paiement', 'cheque')
        ->assertSet('showForm', true)
        ->assertCount('lignes', 1);
});

it('affiche le numero_piece en mode édition', function () {
    $depense = Depense::factory()->create([
        'numero_piece' => '2025-2026:00008',
        'compte_id'    => $this->compte->id,
        'saisi_par'    => $this->user->id,
    ]);

    Livewire::test(DepenseForm::class)
        ->call('edit', $depense->id)
        ->assertSee('2025-2026:00008');
});

it('can update a depense', function () {
    $depense = Depense::factory()->create([
        'libelle' => 'Ancienne dépense',
        'montant_total' => 100.00,
        'mode_paiement' => 'especes',
        'saisi_par' => $this->user->id,
        'compte_id' => $this->compte->id,
    ]);

    // Remove factory-auto-created lignes and make our own
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'montant' => 100.00,
    ]);

    Livewire::test(DepenseForm::class)
        ->call('edit', $depense->id)
        ->set('libelle', 'Dépense mise à jour')
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
        ->assertDispatched('depense-saved');

    $this->assertDatabaseHas('depenses', [
        'id' => $depense->id,
        'libelle' => 'Dépense mise à jour',
        'montant_total' => '75.00',
    ]);

    // Old lignes replaced
    expect(DepenseLigne::where('depense_id', $depense->id)->count())->toBe(1);
});

it('rejette une date avant le début de l\'exercice', function () {
    $user = \App\Models\User::factory()->create();
    session(['exercice_actif' => 2025]); // 2025-09-01 → 2026-08-31
    $compte = \App\Models\CompteBancaire::factory()->create();
    $cat = \App\Models\Categorie::factory()->create(['type' => \App\Enums\TypeCategorie::Depense]);
    $sc  = \App\Models\SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseForm::class)
        ->call('showNewForm')
        ->set('date', '2025-08-31')
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->set('lignes', [['sous_categorie_id' => $sc->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasErrors(['date']);
});

it('rejette une date après la fin de l\'exercice', function () {
    $user = \App\Models\User::factory()->create();
    session(['exercice_actif' => 2025]);
    $compte = \App\Models\CompteBancaire::factory()->create();
    $cat = \App\Models\Categorie::factory()->create(['type' => \App\Enums\TypeCategorie::Depense]);
    $sc  = \App\Models\SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseForm::class)
        ->call('showNewForm')
        ->set('date', '2026-09-01')
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->set('lignes', [['sous_categorie_id' => $sc->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasErrors(['date']);
});

it('accepte une date dans l\'exercice', function () {
    $user = \App\Models\User::factory()->create();
    session(['exercice_actif' => 2025]);
    $compte = \App\Models\CompteBancaire::factory()->create();
    $cat = \App\Models\Categorie::factory()->create(['type' => \App\Enums\TypeCategorie::Depense]);
    $sc  = \App\Models\SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseForm::class)
        ->call('showNewForm')
        ->set('date', '2025-10-01')
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->set('lignes', [['sous_categorie_id' => $sc->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasNoErrors(['date']);
});
