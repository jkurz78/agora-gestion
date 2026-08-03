<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Tiers;
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
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('renders without error', function (): void {
    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->assertOk();
});

it('filtre a_jour retourne les tiers avec cotisation exercice courant', function (): void {
    $aJour = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AJour']);
    $retard = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'EnRetard']);

    Adhesion::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2025]);
    Adhesion::factory()->create(['tiers_id' => $retard->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('AJOUR')
        ->assertDontSee('ENRETARD');
});

it('filtre en_retard retourne les tiers avec cotisation N-1 sans cotisation N', function (): void {
    $aJour = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AJour']);
    $retard = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'EnRetard']);

    Adhesion::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2024]);
    Adhesion::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2025]);
    Adhesion::factory()->create(['tiers_id' => $retard->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('ENRETARD')
        ->assertDontSee('AJOUR');
});

it('filtre tous retourne tous les tiers avec au moins une cotisation', function (): void {
    $avecCot = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AvecCot']);
    $sansCot = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'SansCot']);

    Adhesion::factory()->create(['tiers_id' => $avecCot->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSee('AVECCOT')
        ->assertDontSee('SANSCOT');
});

it('filtre par recherche texte sur le nom', function (): void {
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

    Adhesion::factory()->create(['tiers_id' => $martin->id, 'exercice' => 2025]);
    Adhesion::factory()->create(['tiers_id' => $dupont->id, 'exercice' => 2025]);

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
