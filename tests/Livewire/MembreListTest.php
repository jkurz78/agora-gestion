<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    session(['exercice_actif' => 2025]);
    $this->cotSc = SousCategorie::factory()->create(['pour_cotisations' => true]);
});

/** Helper: create a cotisation transaction for a tiers */
function createCotisation(Tiers $tiers, int $exercice, int $cotScId): Transaction
{
    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'date' => "{$exercice}-10-01",
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $cotScId,
        'exercice' => $exercice,
        'montant' => 30.00,
    ]);

    return $tx;
}

it('renders without error', function (): void {
    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->assertOk();
});

it('filtre a_jour retourne les tiers avec cotisation exercice courant', function (): void {
    $aJour = Tiers::factory()->create(['nom' => 'AJour']);
    $retard = Tiers::factory()->create(['nom' => 'EnRetard']);

    createCotisation($aJour, 2025, $this->cotSc->id);
    createCotisation($retard, 2024, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('AJour')
        ->assertDontSee('EnRetard');
});

it('filtre en_retard retourne les tiers avec cotisation N-1 sans cotisation N', function (): void {
    $aJour = Tiers::factory()->create(['nom' => 'AJour']);
    $retard = Tiers::factory()->create(['nom' => 'EnRetard']);

    createCotisation($aJour, 2024, $this->cotSc->id);
    createCotisation($aJour, 2025, $this->cotSc->id);
    createCotisation($retard, 2024, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('EnRetard')
        ->assertDontSee('AJour');
});

it('filtre tous retourne tous les tiers avec au moins une cotisation', function (): void {
    $avecCot = Tiers::factory()->create(['nom' => 'AvecCot']);
    $sansCot = Tiers::factory()->create(['nom' => 'SansCot']);

    createCotisation($avecCot, 2024, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSee('AvecCot')
        ->assertDontSee('SansCot');
});

it('filtre par recherche texte sur le nom', function (): void {
    $martin = Tiers::factory()->create(['nom' => 'Martin']);
    $dupont = Tiers::factory()->create(['nom' => 'Dupont']);

    createCotisation($martin, 2025, $this->cotSc->id);
    createCotisation($dupont, 2025, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->set('search', 'Martin')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('has default perPage of 20', function (): void {
    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->assertSet('perPage', 20);
});

it('resets to page 1 when perPage changes', function (): void {
    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('perPage', 50)
        ->assertSet('paginators.page', 1);
});
