<?php

declare(strict_types=1);

use App\Livewire\Portail\MesActivites;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Section À venir
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la section À venir avec l\'activité et les sections En cours / Terminées vides', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Parcours de soins',
    ]);

    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cycle Printemps 2026',
        'date_debut' => null,
        'date_fin' => null,
    ]);

    // 2 séances toutes futures
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addMonth(),
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addMonths(2),
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    // H4 titre de la page
    expect($html)->toContain('Mes activités');

    // TypeOperation nom + Opération nom dans la section À venir
    expect($html)->toContain('Parcours de soins');
    expect($html)->toContain('Cycle Printemps 2026');

    // Sections vides pour En cours et Terminées
    expect($html)->toContain('Aucune activité dans cette catégorie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Section En cours
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la section En cours avec l\'activité et les sections À venir / Terminées vides', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Formations',
    ]);

    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation Leadership 2026',
        'date_debut' => null,
        'date_fin' => null,
    ]);

    // Séances chevauchant aujourd'hui : une passée, une future
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subWeek(),
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addWeek(),
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Formations');
    expect($html)->toContain('Formation Leadership 2026');
    expect($html)->toContain('Aucune activité dans cette catégorie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Section Terminées
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la section Terminées avec l\'activité et les sections À venir / En cours vides', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Ateliers bien-être',
    ]);

    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Atelier Yoga Été 2025',
        'date_debut' => null,
        'date_fin' => null,
    ]);

    // 2 séances toutes passées
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subMonths(3),
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subMonth(),
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Ateliers bien-être');
    expect($html)->toContain('Atelier Yoga Été 2025');
    expect($html)->toContain('Aucune activité dans cette catégorie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Vocabulaire — "opération" interdit dans le rendu HTML
// ─────────────────────────────────────────────────────────────────────────────
it('n\'expose jamais le mot « opération » dans le HTML rendu', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Cycle thérapeutique',
    ]);

    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cycle Automne 2025',
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    $component = Livewire::test(MesActivites::class, ['association' => $asso]);

    // Interdit mot français accentué (case-insensitive)
    $component->assertDontSee('opération', false);
    $component->assertDontSee('opérations', false);
    // Interdit mot sans accent (exact casse)
    $component->assertDontSee('Operation');
    $component->assertDontSee('Operations');
});

// ─────────────────────────────────────────────────────────────────────────────
// Tests Step 4 : Sous-tabs par TypeOperation
// ─────────────────────────────────────────────────────────────────────────────

it('affiche les sous-tabs nav-pills si le tiers a des participations sur 2 types distincts', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeA = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Formations',
    ]);
    $typeB = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Parcours de soins',
    ]);

    $opA = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeA->id,
        'nom' => 'Formation Leadership',
    ]);
    $opB = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeB->id,
        'nom' => 'Suivi Printemps',
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opA->id,
    ]);
    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opB->id,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('nav-pills');
    expect($html)->toContain('Formations');
    expect($html)->toContain('Parcours de soins');
});

it('n\'affiche pas les sous-tabs nav-pills si le tiers a des participations sur 1 seul type', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeA = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Formations',
    ]);

    $opA = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeA->id,
        'nom' => 'Formation Alpha',
    ]);
    $opB = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeA->id,
        'nom' => 'Formation Beta',
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opA->id,
    ]);
    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opB->id,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->not->toContain('nav-pills');
});

it('le tab actif par défaut est le premier type alphabétique', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeA = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Formations',
    ]);
    $typeB = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Parcours de soins',
    ]);

    $opA = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeA->id,
        'nom' => 'Formation Alpha',
    ]);
    $opB = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeB->id,
        'nom' => 'Suivi Printemps',
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opA->id,
    ]);
    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opB->id,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    // "Formations" est premier alphabétique — son bouton doit avoir class "active"
    expect($html)->toContain('Formations');
    // Le bouton Formations doit apparaître avant "active" (dans un nav-link active)
    $posFormations = strpos($html, 'Formations');
    $posActive = strpos($html, 'active');
    expect($posActive)->toBeLessThan($posFormations + 200); // active proche de Formations
    // Parcours de soins ne doit pas être actif (pas active sur son bouton)
    expect($html)->not->toMatch('/Parcours de soins[^<]*active/');
});

it('changer de tab filtre les activités affichées', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeA = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Formations',
    ]);
    $typeB = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Parcours de soins',
    ]);

    $opA = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeA->id,
        'nom' => 'Formation Unique',
    ]);
    $opB = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeB->id,
        'nom' => 'Suivi Unique',
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opA->id,
    ]);
    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opB->id,
    ]);

    // Sélectionner le type B (Parcours de soins)
    $component = Livewire::test(MesActivites::class, ['association' => $asso])
        ->set('typeOperationId', (int) $typeB->id);

    $html = $component->html();

    expect($html)->toContain('Suivi Unique');
    expect($html)->not->toContain('Formation Unique');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Sans participation — 3 sections vides
// ─────────────────────────────────────────────────────────────────────────────
it('affiche les 3 sections avec message vide quand le tiers n\'a aucune participation', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Mes activités');
    expect($html)->toContain('À venir');
    expect($html)->toContain('En cours');
    expect($html)->toContain('Terminée');

    // Tous les messages vides présents (3 fois)
    expect(substr_count($html, 'Aucune activité dans cette catégorie'))->toBe(3);
});
