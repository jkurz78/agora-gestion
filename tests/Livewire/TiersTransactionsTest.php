<?php

declare(strict_types=1);

use App\Livewire\TiersTransactions;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user  = User::factory()->create();
    $this->tiers = Tiers::factory()->create(['nom' => 'Dupont']);
});

it('renders the component', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertOk()
        ->assertSee('Dupont');
});

it('affiche un message quand aucune transaction', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertSee('Aucune transaction');
});

it('affiche les dépenses du tiers', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Achat test', 'date' => '2025-10-01']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertSee('Achat test');
});

it('filtre par type', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Ma dépense', 'date' => '2025-10-01']);
    Don::factory()->create(['tiers_id' => $this->tiers->id, 'objet' => 'Mon don', 'date' => '2025-10-01']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->set('typeFilter', 'don')
        ->assertSee('Mon don')
        ->assertDontSee('Ma dépense');
});

it('filtre par recherche texte', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Frais transport', 'date' => '2025-10-01']);
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Loyer bureau', 'date' => '2025-10-01']);

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
