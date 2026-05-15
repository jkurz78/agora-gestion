<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Livewire\Portail\MesActivites;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
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
// Helper : crée une opération En cours (1 séance passée + 1 future)
// ─────────────────────────────────────────────────────────────────────────────
function makeEnCoursOperation(Association $asso, TypeOperation $typeOp): Operation
{
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Stage En cours',
        'date_debut' => null,
        'date_fin' => null,
    ]);

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

    return $operation;
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Timeline visible dans la section En cours
// ─────────────────────────────────────────────────────────────────────────────
it('affiche la timeline des séances pour les cartes En cours', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Formation']);
    $operation = makeEnCoursOperation($asso, $typeOp);

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

    expect($html)->toContain('seance-timeline');
    // 2 séances = 2 <li> items
    expect(substr_count($html, '<li class="d-flex align-items-center'))->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : 4 statuts de présence → bonnes classes CSS
// ─────────────────────────────────────────────────────────────────────────────
it('mappe les 4 statuts de présence aux bonnes classes CSS dans la timeline', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Atelier']);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Atelier Couleurs',
        'date_debut' => null,
        'date_fin' => null,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    // 4 séances passées avec statuts différents
    $seancePresent = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subDays(10),
    ]);
    $seanceExcuse = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subDays(9),
    ]);
    $seanceAbsent = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subDays(8),
    ]);
    $seanceArret = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subDays(7),
    ]);
    // 1 séance future sans présence → pastille-future
    $seanceFuture = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addDays(7),
    ]);
    // 1 séance passée sans présence → bg-light
    $seanceSansPresence = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subDays(6),
    ]);

    // Créer les présences (encrypted cast — on passe la string value)
    Presence::create([
        'participant_id' => $participant->id,
        'seance_id' => $seancePresent->id,
        'statut' => StatutPresence::Present->value,
    ]);
    Presence::create([
        'participant_id' => $participant->id,
        'seance_id' => $seanceExcuse->id,
        'statut' => StatutPresence::Excuse->value,
    ]);
    Presence::create([
        'participant_id' => $participant->id,
        'seance_id' => $seanceAbsent->id,
        'statut' => StatutPresence::AbsenceNonJustifiee->value,
    ]);
    Presence::create([
        'participant_id' => $participant->id,
        'seance_id' => $seanceArret->id,
        'statut' => StatutPresence::Arret->value,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('seance-timeline');
    expect($html)->toContain('bg-success');
    expect($html)->toContain('bg-warning');
    expect($html)->toContain('bg-danger');
    expect($html)->toContain('bg-secondary');
    expect($html)->toContain('pastille-future');
    expect($html)->toContain('bg-light');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Pas de timeline dans la section À venir
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas de timeline pour les cartes À venir', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Cours']);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cours futur',
        'date_debut' => null,
        'date_fin' => null,
    ]);

    // Toutes les séances dans le futur → classifié À venir
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

    expect($html)->not->toContain('seance-timeline');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Pas de timeline dans la section Terminées
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas de timeline pour les cartes Terminées', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Séminaire']);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Séminaire passé',
        'date_debut' => null,
        'date_fin' => null,
    ]);

    // Toutes les séances dans le passé → classifié Terminée
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subMonths(2),
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

    expect($html)->not->toContain('seance-timeline');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Opération En cours sans séance → pas de timeline
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas de timeline pour une opération En cours sans séances', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Projet']);
    // Pas de séances — En cours par default si date_debut est passé
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Projet sans séances',
        'date_debut' => now()->subMonth(),
        'date_fin' => now()->addMonth(),
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

    expect($html)->not->toContain('seance-timeline');
});
