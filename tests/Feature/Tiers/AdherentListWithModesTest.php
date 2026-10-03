<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\Adhesion;
use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->compteCotisation = Compte::factory()->pourCotisations()->create();
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    session()->forget('exercice_actif');
});

it('filtre a_jour inclut un adhérent en mode durée dont la période recouvre l\'exercice sélectionné', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'DUREE_AJOUR']);
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['compte_id' => $this->compteCotisation->id]);

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

it('filtre a_jour exclut un adhérent en mode durée dont la période est échue avant l\'exercice sélectionné', function (): void {
    // Référence unique : l'exercice sélectionné (2025-2026, du 01/09/2025 au 31/08/2026),
    // pas la date du jour (spec 2026-10-02, D3).
    $tiers = Tiers::factory()->create(['nom' => 'DUREE_EXPIRE']);
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['compte_id' => $this->compteCotisation->id]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => '2024-01-15',
        'date_fin' => '2025-01-14',
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertDontSee('DUREE_EXPIRE');
});

it('filtre en_retard inclut un adhérent en mode durée échu pendant l\'exercice précédent', function (): void {
    // Échu le 15/08/2025, soit pendant l'exercice 2024-2025 : à jour hier, plus aujourd'hui.
    $tiers = Tiers::factory()->create(['nom' => 'DUREE_RETARD']);
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['compte_id' => $this->compteCotisation->id]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => '2024-10-01',
        'date_fin' => '2025-08-15',
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('DUREE_RETARD');
});

it('affiche le badge de la formule sur la ligne (mode exercice)', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'AVEC_FORMULE']);
    $formule = FormuleAdhesion::factory()->create([
        'compte_id' => $this->compteCotisation->id,
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
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create(['compte_id' => $this->compteCotisation->id]);
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

it('filtre a_jour inclut les adhésions mode illimite', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'PERMANENT']);
    $formule = FormuleAdhesion::factory()->modeIllimite()->create([
        'compte_id' => Compte::factory()->pourCotisations()->create()->id,
    ]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => now()->subYears(5)->toDateString(),
        'date_fin' => null,
        'mode' => 'illimite',
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('PERMANENT');
});

it('AdherentList affiche le badge Permanente pour les adhésions illimite', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'BADGE_PERM']);
    $formule = FormuleAdhesion::factory()->modeIllimite()->create([
        'compte_id' => Compte::factory()->pourCotisations()->create()->id,
    ]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'exercice' => null,
        'date_debut' => now()->subYears(2)->toDateString(),
        'date_fin' => null,
        'mode' => 'illimite',
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('Permanente');
});
