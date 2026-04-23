<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->cotSc = SousCategorie::factory()->pourCotisations()->create(['association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

/** Helper: create a cotisation transaction for a tiers */
function createCotisation(Tiers $tiers, int $exercice, int $cotScId): Transaction
{
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => TenantContext::currentId(),
        'tiers_id' => $tiers->id,
        'date' => "{$exercice}-10-01",
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $cotScId,
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
    $aJour = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AJour']);
    $retard = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'EnRetard']);

    createCotisation($aJour, 2025, $this->cotSc->id);
    createCotisation($retard, 2024, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('AJOUR')
        ->assertDontSee('ENRETARD');
});

it('filtre en_retard retourne les tiers avec cotisation N-1 sans cotisation N', function (): void {
    $aJour = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AJour']);
    $retard = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'EnRetard']);

    createCotisation($aJour, 2024, $this->cotSc->id);
    createCotisation($aJour, 2025, $this->cotSc->id);
    createCotisation($retard, 2024, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('ENRETARD')
        ->assertDontSee('AJOUR');
});

it('filtre tous retourne tous les tiers avec au moins une cotisation', function (): void {
    $avecCot = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AvecCot']);
    $sansCot = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'SansCot']);

    createCotisation($avecCot, 2024, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSee('AVECCOT')
        ->assertDontSee('SANSCOT');
});

it('filtre par recherche texte sur le nom', function (): void {
    // Prénoms fixes pour éviter la flakiness fr_FR : fake()->firstName() peut
    // retourner "Martine" (contient "Martin") et faire matcher la recherche
    // sur le prenom de l'autre tiers.
    $martin = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Martin',
        'prenom' => 'Alice',
    ]);
    $dupont = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
        'prenom' => 'Bernard',
    ]);

    createCotisation($martin, 2025, $this->cotSc->id);
    createCotisation($dupont, 2025, $this->cotSc->id);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->set('search', 'Martin')
        ->assertSee('MARTIN')
        ->assertDontSee('DUPONT');
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
