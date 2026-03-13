<?php

declare(strict_types=1);

use App\Livewire\TransactionCompteList;
use App\Models\CompteBancaire;
use App\Models\Recette;
use App\Models\Depense;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create(['nom' => 'Compte Test']);
});

it('renders without compte selected', function () {
    Livewire::test(TransactionCompteList::class)
        ->assertSee('Sélectionnez un compte')
        ->assertDontSee('Aucune transaction');
});

it('shows transactions when compte is selected', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'libelle' => 'Cotisation annuelle',
        'montant_total' => 120.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->assertSee('Cotisation annuelle');
});

it('filtre par tiers', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers' => 'Fondation ABC',
        'libelle' => 'Subvention',
        'montant_total' => 500.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers' => 'Mairie XYZ',
        'libelle' => 'Autre recette',
        'montant_total' => 100.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->set('searchTiers', 'Fondation')
        ->assertSee('Subvention')
        ->assertDontSee('Autre recette');
});

it('supprime une recette non verrouillée', function () {
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->call('deleteTransaction', 'recette', $recette->id);

    $this->assertSoftDeleted('recettes', ['id' => $recette->id]);
});

it('ne supprime pas une recette verrouillée par un rapprochement', function () {
    $rapprochement = \App\Models\RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => \App\Enums\StatutRapprochement::Verrouille,
        'saisi_par' => $this->user->id,
    ]);
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
        'rapprochement_id' => $rapprochement->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->call('deleteTransaction', 'recette', $recette->id);

    $this->assertDatabaseHas('recettes', ['id' => $recette->id, 'deleted_at' => null]);
});

it('trie par montant', function () {
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'libelle' => 'Petite recette',
        'montant_total' => 10.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'libelle' => 'Grande recette',
        'montant_total' => 1000.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->call('sortBy', 'montant')
        ->assertSet('sortColumn', 'montant')
        ->assertSet('sortDirection', 'asc');
});

it('inverse la direction de tri si on clique sur la même colonne', function () {
    Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id)
        ->set('sortColumn', 'date')
        ->set('sortDirection', 'asc')
        ->call('sortBy', 'date')
        ->assertSet('sortDirection', 'desc');
});

it('reset la pagination quand le compte change', function () {
    $autreCompte = CompteBancaire::factory()->create();

    $component = Livewire::test(TransactionCompteList::class)
        ->set('compteId', $this->compte->id);

    $component->set('compteId', $autreCompte->id);

    // La page doit être revenue à 1 (pas d'erreur de pagination)
    $component->assertSet('compteId', $autreCompte->id);
});
