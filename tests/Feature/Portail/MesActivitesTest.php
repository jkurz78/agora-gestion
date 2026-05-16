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

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    // H4 titre dynamique
    expect($html)->toContain('Mes parcours de soins');

    // Nom de l'activité
    expect($html)->toContain('Cycle Printemps 2026');

    // H5 sous-section "À venir" présente, les deux autres absentes (vides → masquées)
    expect($html)->toContain('<h5');
    expect($html)->toContain('À venir');
    expect($html)->not->toContain('Terminées');
    expect($html)->not->toMatch('/<h5[^>]*>En cours</');

    // Pas de message vide générique
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

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Mes formations');
    expect($html)->toContain('Formation Leadership 2026');
    // H5 "En cours" présent
    expect($html)->toMatch('/<h5[^>]*>En cours</');
    // H5 "À venir" et "Terminées" absents (vides → masqués)
    expect($html)->not->toMatch('/<h5[^>]*>À venir</');
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

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Mes ateliers bien-être');
    expect($html)->toContain('Atelier Yoga Été 2025');
    // H5 "Terminées" présent
    expect($html)->toMatch('/<h5[^>]*>Terminées</');
    // H5 "À venir" et "En cours" absents (vides → masqués)
    expect($html)->not->toMatch('/<h5[^>]*>À venir</');
    expect($html)->not->toMatch('/<h5[^>]*>En cours</');
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

    $component = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp]);

    // Interdit mot français accentué (case-insensitive)
    $component->assertDontSee('opération', false);
    $component->assertDontSee('opérations', false);
    // Interdit mot sans accent (exact casse)
    $component->assertDontSee('Operation');
    $component->assertDontSee('Operations');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Sans participation pour ce type → 403
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 403 quand le tiers n\'a aucune participation pour ce type', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Formations']);
    $autreType = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Ateliers']);

    $op = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    // Tente d'accéder à un type pour lequel le tiers n'est pas inscrit
    Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $autreType])
        ->assertStatus(403);
});
