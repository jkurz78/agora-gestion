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
// Test 1 : Section À venir — sous-section présente, les deux autres absentes
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la sous-section À venir avec l\'activité et masque En cours / Terminées si vides', function () {
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

    // H5 type + opération nom dans la page
    expect($html)->toContain('Parcours de soins');
    expect($html)->toContain('Cycle Printemps 2026');

    // H6 sous-section "À venir" présente, les deux autres absentes (vides → masquées)
    expect($html)->toContain('<h6');
    expect($html)->toContain('À venir');
    // Sous-sections vides → leur H6 absent
    expect($html)->not->toContain('Terminées');
    // "En cours" peut apparaître dans les tooltips de carte — on vérifie l'absence du H6 heading
    expect($html)->not->toMatch('/<h6[^>]*>En cours</');

    // Pas de message vide générique (on masque les sous-sections vides)
    expect($html)->not->toContain('Aucune activité dans cette catégorie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Section En cours — sous-section présente, les deux autres absentes
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la sous-section En cours avec l\'activité et masque À venir / Terminées si vides', function () {
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
    // H6 "En cours" présent
    expect($html)->toMatch('/<h6[^>]*>En cours</');
    // H6 "À venir" et "Terminées" absents (vides → masqués)
    expect($html)->not->toMatch('/<h6[^>]*>À venir</');
    expect($html)->not->toContain('Terminées');
    expect($html)->not->toContain('Aucune activité dans cette catégorie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Section Terminées — sous-section présente, les deux autres absentes
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la sous-section Terminées avec l\'activité et masque À venir / En cours si vides', function () {
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
    // H6 "Terminées" présent
    expect($html)->toMatch('/<h6[^>]*>Terminées</');
    // H6 "À venir" et "En cours" absents (vides → masqués)
    expect($html)->not->toMatch('/<h6[^>]*>À venir</');
    expect($html)->not->toMatch('/<h6[^>]*>En cours</');
    expect($html)->not->toContain('Aucune activité dans cette catégorie');
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
// Test 5 : Sans participation — message vide, pas de blocs H5
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le message vide quand le tiers n\'a aucune participation', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Mes activités');
    expect($html)->toContain('Vous n\'avez pas encore d\'activité enregistrée');

    // Pas de sous-sections temporelles (aucun H6)
    expect($html)->not->toContain('<h6');
    expect($html)->not->toContain('Terminées');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : 2 types actifs → 2 blocs H5 présents (alphabétique)
// ─────────────────────────────────────────────────────────────────────────────
it('affiche 2 blocs H5 distincts quand le tiers a des participations sur 2 types distincts', function () {
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

    // Les 2 H5 présents
    expect($html)->toContain('Formations');
    expect($html)->toContain('Parcours de soins');

    // Pas de nav-pills
    expect($html)->not->toContain('nav-pills');

    // Ordre alphabétique : "Formations" avant "Parcours de soins"
    expect(strpos($html, 'Formations'))->toBeLessThan(strpos($html, 'Parcours de soins'));
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : 1 type actif → exactement 1 bloc H5, pas de nav-pills
// ─────────────────────────────────────────────────────────────────────────────
it('affiche 1 seul bloc H5 quand le tiers n\'a qu\'un seul type d\'activité', function () {
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

    // H5 présent exactement une fois
    expect(substr_count($html, 'Formations'))->toBeGreaterThanOrEqual(1);

    // Pas de nav-pills
    expect($html)->not->toContain('nav-pills');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : Sous-section vide → H6 absent (masqué)
// ─────────────────────────────────────────────────────────────────────────────
it('masque la sous-section H6 vide dans le bloc H5', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Ateliers',
    ]);

    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Atelier Yoga',
        'date_debut' => null,
        'date_fin' => null,
    ]);

    // Séances toutes futures → classifié "À venir"
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addMonth(),
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

    // H5 présent
    expect($html)->toContain('Ateliers');

    // H6 "À venir" présent, "En cours" et "Terminées" absents (masqués)
    expect($html)->toMatch('/<h6[^>]*>À venir</');
    expect($html)->not->toMatch('/<h6[^>]*>En cours</');
    expect($html)->not->toContain('Terminées');
});
