<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    $this->compteVentilation = Compte::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('RapportCompteResultatOperations n\'affiche pas les opérations hors exercice', function () {
    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteVentilation->id,
    ]);
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Op hors exercice',
        'date_debut' => '2024-09-01',
        'date_fin' => '2025-08-30',
        'statut' => StatutOperation::EnCours,
    ]);

    $component = Livewire::test(RapportCompteResultatOperations::class);
    $tree = $component->viewData('operationTree');
    $allNames = collect($tree)->flatMap(fn ($sc) => collect($sc['types'])->flatMap(fn ($t) => collect($t['operations'])->pluck('nom')));
    expect($allNames)->not->toContain('Op hors exercice');
});

it('RapportCompteResultatOperations affiche les opérations clôturées dans l\'exercice', function () {
    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteVentilation->id,
    ]);
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Op clôturée visible',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    $component = Livewire::test(RapportCompteResultatOperations::class);
    $tree = $component->viewData('operationTree');
    $allNames = collect($tree)->flatMap(fn ($sc) => collect($sc['types'])->flatMap(fn ($t) => collect($t['operations'])->pluck('nom')));
    expect($allNames)->toContain('Op clôturée visible');
});
