<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Livewire\CommunicationTiers;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($this->admin);

    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Test Asso', 'email_from' => 'test@asso.fr'])->save();
});

// --- Access control ---

it('renders for admin', function () {
    Livewire::test(CommunicationTiers::class)->assertOk();
});

it('renders for gestionnaire', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Gestionnaire]));
    Livewire::test(CommunicationTiers::class)->assertOk();
});

it('aborts for comptable', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Comptable]));
    Livewire::test(CommunicationTiers::class)->assertForbidden();
});

it('aborts for consultation', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Consultation]));
    Livewire::test(CommunicationTiers::class)->assertForbidden();
});

// --- Search ---

it('filters tiers by search text', function () {
    Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean@example.com']);
    Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Paul', 'email' => 'paul@example.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('search', 'Dupont')
        ->assertSee('DUPONT')
        ->assertDontSee('MARTIN');
});

// --- Opt-out exclusion ---

it('shows opted-out tiers as greyed out', function () {
    Tiers::factory()->create(['nom' => 'Optout', 'email' => 'out@example.com', 'email_optout' => true]);

    Livewire::test(CommunicationTiers::class)
        ->assertSee('Désinscrit');
});

// --- Fournisseurs filter ---

it('filters by fournisseurs', function () {
    Tiers::factory()->create(['nom' => 'Fourn', 'email' => 'f@e.com', 'pour_depenses' => true]);
    Tiers::factory()->create(['nom' => 'Client', 'email' => 'c@e.com', 'pour_depenses' => false, 'pour_recettes' => true]);

    Livewire::test(CommunicationTiers::class)
        ->set('filtreFournisseurs', true)
        ->assertSee('FOURN')
        ->assertDontSee('CLIENT');
});

// --- Clients filter ---

it('filters by clients', function () {
    Tiers::factory()->create(['nom' => 'Cli', 'email' => 'c@e.com', 'pour_recettes' => true, 'pour_depenses' => false]);
    Tiers::factory()->create(['nom' => 'Autre', 'email' => 'a@e.com', 'pour_recettes' => false, 'pour_depenses' => false]);

    Livewire::test(CommunicationTiers::class)
        ->set('filtreClients', true)
        ->assertSee('CLI')
        ->assertDontSee('AUTRE');
});

// --- Donateurs filter ---

it('filters by donateurs tous exercices', function () {
    $cat = Categorie::create(['nom' => 'Recettes', 'type' => 'recette']);
    $sc = SousCategorie::create(['categorie_id' => $cat->id, 'nom' => 'Dons', 'pour_dons' => true]);

    $donateur = Tiers::factory()->create(['nom' => 'Donateur', 'email' => 'd@e.com']);
    $tx = Transaction::factory()->create(['tiers_id' => $donateur->id, 'type' => 'recette', 'date' => '2025-10-15']);
    TransactionLigne::factory()->create(['transaction_id' => $tx->id, 'sous_categorie_id' => $sc->id]);

    $nonDonateur = Tiers::factory()->create(['nom' => 'NonDon', 'email' => 'n@e.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('filtreDonateurs', 'tous')
        ->assertSee('DONATEUR')
        ->assertDontSee('NONDON');
});

// --- AND/OR mode ---

it('combines filters in AND mode by default', function () {
    Tiers::factory()->create(['nom' => 'Both', 'email' => 'b@e.com', 'pour_depenses' => true, 'pour_recettes' => true]);
    Tiers::factory()->create(['nom' => 'OnlyF', 'email' => 'f@e.com', 'pour_depenses' => true, 'pour_recettes' => false]);

    Livewire::test(CommunicationTiers::class)
        ->set('modeFiltres', 'et')
        ->set('filtreFournisseurs', true)
        ->set('filtreClients', true)
        ->assertSee('BOTH')
        ->assertDontSee('ONLYF');
});

it('combines filters in OR mode', function () {
    Tiers::factory()->create(['nom' => 'Fourn', 'email' => 'f@e.com', 'pour_depenses' => true, 'pour_recettes' => false]);
    Tiers::factory()->create(['nom' => 'Client', 'email' => 'c@e.com', 'pour_depenses' => false, 'pour_recettes' => true]);
    Tiers::factory()->create(['nom' => 'Neither', 'email' => 'n@e.com', 'pour_depenses' => false, 'pour_recettes' => false]);

    Livewire::test(CommunicationTiers::class)
        ->set('modeFiltres', 'ou')
        ->set('filtreFournisseurs', true)
        ->set('filtreClients', true)
        ->assertSee('FOURN')
        ->assertSee('CLIENT')
        ->assertDontSee('NEITHER');
});

// --- Selection ---

it('toggleSelectAll selects all filtered tiers with email', function () {
    Tiers::factory()->create(['nom' => 'A', 'email' => 'a@e.com']);
    Tiers::factory()->create(['nom' => 'B', 'email' => 'b@e.com']);
    Tiers::factory()->create(['nom' => 'C', 'email' => null]); // no email
    Tiers::factory()->create(['nom' => 'D', 'email' => 'd@e.com', 'email_optout' => true]); // opted out

    Livewire::test(CommunicationTiers::class)
        ->call('toggleSelectAll')
        ->assertSet('selectAll', true)
        ->assertCount('selectedTiersIds', 2); // only A and B
});
