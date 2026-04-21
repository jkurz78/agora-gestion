<?php

declare(strict_types=1);

use App\Livewire\CommunicationTiers;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create([
        'email_from' => 'test@asso.fr',
    ]);
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->admin);
});

afterEach(function () {
    TenantContext::clear();
});

// --- Access control ---

it('renders for admin', function () {
    Livewire::test(CommunicationTiers::class)->assertOk();
});

it('renders for gestionnaire', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);
    $this->actingAs($user);
    Livewire::test(CommunicationTiers::class)->assertOk();
});

it('aborts for comptable', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);
    $this->actingAs($user);
    Livewire::test(CommunicationTiers::class)->assertForbidden();
});

it('aborts for consultation', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);
    $this->actingAs($user);
    Livewire::test(CommunicationTiers::class)->assertForbidden();
});

// --- Search ---

it('filters tiers by search text', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean@example.com']);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Martin', 'prenom' => 'Paul', 'email' => 'paul@example.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('search', 'Dupont')
        ->assertSee('DUPONT')
        ->assertDontSee('MARTIN');
});

// --- Opt-out exclusion ---

it('shows opted-out tiers as greyed out', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Optout', 'email' => 'out@example.com', 'email_optout' => true]);

    Livewire::test(CommunicationTiers::class)
        ->assertSee('Désinscrit');
});

// --- Fournisseurs filter ---

it('filters by fournisseurs', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Fourn', 'email' => 'f@e.com', 'pour_depenses' => true]);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Client', 'email' => 'c@e.com', 'pour_depenses' => false, 'pour_recettes' => true]);

    Livewire::test(CommunicationTiers::class)
        ->set('filtreFournisseurs', true)
        ->assertSee('FOURN')
        ->assertDontSee('CLIENT');
});

// --- Clients filter ---

it('filters by clients', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Cli', 'email' => 'c@e.com', 'pour_recettes' => true, 'pour_depenses' => false]);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Autre', 'email' => 'a@e.com', 'pour_recettes' => false, 'pour_depenses' => false]);

    Livewire::test(CommunicationTiers::class)
        ->set('filtreClients', true)
        ->assertSee('CLI')
        ->assertDontSee('AUTRE');
});

// --- Donateurs filter ---

it('filters by donateurs tous exercices', function () {
    $cat = Categorie::create(['association_id' => $this->association->id, 'nom' => 'Recettes', 'type' => 'recette']);
    $sc = SousCategorie::factory()->pourDons()->create(['association_id' => $this->association->id, 'categorie_id' => $cat->id, 'nom' => 'Dons']);

    $donateur = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Donateur', 'email' => 'd@e.com']);
    $tx = Transaction::factory()->create(['association_id' => $this->association->id, 'tiers_id' => $donateur->id, 'type' => 'recette', 'date' => '2025-10-15']);
    TransactionLigne::factory()->create(['transaction_id' => $tx->id, 'sous_categorie_id' => $sc->id]);

    $nonDonateur = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'NonDon', 'email' => 'n@e.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('filtreDonateurs', 'tous')
        ->assertSee('DONATEUR')
        ->assertDontSee('NONDON');
});

// --- AND/OR mode ---

it('combines filters in AND mode by default', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Both', 'email' => 'b@e.com', 'pour_depenses' => true, 'pour_recettes' => true]);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'OnlyF', 'email' => 'f@e.com', 'pour_depenses' => true, 'pour_recettes' => false]);

    Livewire::test(CommunicationTiers::class)
        ->set('modeFiltres', 'et')
        ->set('filtreFournisseurs', true)
        ->set('filtreClients', true)
        ->assertSee('BOTH')
        ->assertDontSee('ONLYF');
});

it('combines filters in OR mode', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Fourn', 'email' => 'f@e.com', 'pour_depenses' => true, 'pour_recettes' => false]);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Client', 'email' => 'c@e.com', 'pour_depenses' => false, 'pour_recettes' => true]);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Neither', 'email' => 'n@e.com', 'pour_depenses' => false, 'pour_recettes' => false]);

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
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'A', 'email' => 'a@e.com']);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'B', 'email' => 'b@e.com']);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'C', 'email' => null]); // no email
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'D', 'email' => 'd@e.com', 'email_optout' => true]); // opted out

    Livewire::test(CommunicationTiers::class)
        ->call('toggleSelectAll')
        ->assertSet('selectAll', true)
        ->assertCount('selectedTiersIds', 2); // only A and B
});
