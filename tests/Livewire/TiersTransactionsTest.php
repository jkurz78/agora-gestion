<?php

declare(strict_types=1);

use App\Livewire\TiersTransactions;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont']);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('renders the component', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertOk()
        ->assertSee('DUPONT');
});

it('affiche un message quand aucune transaction', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertSee('Aucune transaction');
});

it('affiche les dépenses du tiers', function (): void {
    Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'tiers_id' => $this->tiers->id, 'libelle' => 'Achat test', 'date' => '2025-10-01']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertSee('Achat test');
});

it('filtre par type', function (): void {
    Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'tiers_id' => $this->tiers->id, 'libelle' => 'Ma dépense', 'date' => '2025-10-01']);
    Transaction::factory()->asRecette()->create(['association_id' => $this->association->id, 'tiers_id' => $this->tiers->id, 'libelle' => 'Ma recette', 'date' => '2025-10-01']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->set('typeFilter', 'recette')
        ->assertSee('Ma recette')
        ->assertDontSee('Ma dépense');
});

it('filtre par recherche texte', function (): void {
    Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'tiers_id' => $this->tiers->id, 'libelle' => 'Frais transport', 'date' => '2025-10-01']);
    Transaction::factory()->asDepense()->create(['association_id' => $this->association->id, 'tiers_id' => $this->tiers->id, 'libelle' => 'Loyer bureau', 'date' => '2025-10-01']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->set('search', 'Loyer')
        ->assertSee('Loyer bureau')
        ->assertDontSee('Frais transport');
});

it('bascule la direction du tri sur la même colonne', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->call('sort', 'montant')
        ->assertSet('sortBy', 'montant')
        ->assertSet('sortDir', 'asc')
        ->call('sort', 'montant')
        ->assertSet('sortDir', 'desc');
});

it('remet sortDir à asc quand on change de colonne', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->call('sort', 'montant')
        ->call('sort', 'date')
        ->assertSet('sortBy', 'date')
        ->assertSet('sortDir', 'asc');
});
