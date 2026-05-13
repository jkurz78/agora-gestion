<?php

declare(strict_types=1);

use App\Livewire\Tiers\FicheTiers;
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
});

it('affiche la formule et l\'intervalle dates en mode durée', function (): void {
    $tiers = Tiers::factory()->create();
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Adhésion glissante',
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => '2025-10-15',
        'date_fin' => '2026-10-15',
    ]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->set('onglet', 'adhesion')
        ->assertSee('Adhésion glissante')
        ->assertSee('15/10/2025')
        ->assertSee('15/10/2026');
});

it('affiche la formule et l\'exercice en mode exercice', function (): void {
    $tiers = Tiers::factory()->create();
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
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->set('onglet', 'adhesion')
        ->assertSee('Adhésion adulte 2025');
});

it('reste fonctionnel pour une adhésion legacy sans formule', function (): void {
    $tiers = Tiers::factory()->create();
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => null,
        'exercice' => 2025,
    ]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->set('onglet', 'adhesion')
        ->assertOk();
});

it('onglet Adhésion fiche tiers affiche Permanente pour les adhésions illimite', function (): void {
    $tiers = Tiers::factory()->create();
    $formule = FormuleAdhesion::factory()->modeIllimite()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Membre à vie',
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => '2020-01-15',
        'date_fin' => null,
        'mode' => 'illimite',
    ]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->set('onglet', 'adhesion')
        ->assertSee('Membre à vie')
        ->assertSee('Permanente');
});

it('onglet Adhésion fiche tiers affiche le badge Déductible si snapshot fiscal', function (): void {
    $tiers = Tiers::factory()->create();
    $formule = FormuleAdhesion::factory()->deductible()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Adhésion bienfaiteur',
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => 2025,
        'deductible_fiscal' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->set('onglet', 'adhesion')
        ->assertSee('Déductible');
});

it('onglet Adhésion fiche tiers n\'affiche pas le badge Déductible si snapshot non déductible', function (): void {
    $tiers = Tiers::factory()->create();
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Adhésion classique',
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => 2025,
        'deductible_fiscal' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->set('onglet', 'adhesion')
        ->assertDontSee('Déductible');
});
