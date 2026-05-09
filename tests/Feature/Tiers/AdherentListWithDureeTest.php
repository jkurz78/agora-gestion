<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\Adhesion;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    session()->forget('exercice_actif');
});

it('filtre a_jour inclut un adhérent en mode durée dont la période couvre aujourd\'hui', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'DUREE_AJOUR']);
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['sous_categorie_id' => $this->sc->id]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => now()->subMonths(2)->toDateString(),
        'date_fin' => now()->addMonths(10)->toDateString(),
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('DUREE_AJOUR');
});

it('filtre a_jour exclut un adhérent en mode durée dont la période est expirée', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'DUREE_EXPIRE']);
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['sous_categorie_id' => $this->sc->id]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => now()->subMonths(15)->toDateString(),
        'date_fin' => now()->subMonths(3)->toDateString(),
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertDontSee('DUREE_EXPIRE');
});

it('filtre en_retard inclut un adhérent en mode durée expiré dans les 30 derniers jours', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'DUREE_RETARD']);
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['sous_categorie_id' => $this->sc->id]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => now()->subMonths(13)->toDateString(),
        'date_fin' => now()->subDays(15)->toDateString(),
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('DUREE_RETARD');
});

it('affiche le badge de la formule sur la ligne (mode exercice)', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'AVEC_FORMULE']);
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Adhésion adulte 2025',
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => 2025,
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('Adhésion adulte 2025');
});

it('affiche l\'intervalle de validité en mode durée', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'DUREE_AFFICHE']);
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['sous_categorie_id' => $this->sc->id]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => '2025-10-15',
        'date_fin' => '2026-10-15',
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('15/10/2025')
        ->assertSee('15/10/2026');
});
