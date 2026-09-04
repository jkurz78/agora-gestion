<?php

declare(strict_types=1);

// Une opération ventilée mais sans le moindre mouvement doit être éligible au
// rapport budget — sinon elle disparaît du sélecteur ET de l'onglet de sa
// propre fiche, normaliser() intersectant la sélection avec les éligibles.

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

it('une operation ventilee sans aucun mouvement est eligible au rapport budget', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    $service = app(RapportService::class);

    // Le comportement historique ne change pas : sans mouvement, pas éligible.
    expect($service->operationsEligibles(2025))->not->toContain((int) $operation->id);

    // Le nouveau drapeau la fait entrer.
    expect($service->operationsEligibles(2025, avecBudget: true))
        ->toContain((int) $operation->id);
});

it('la ventilation d une autre association n eligibilise rien', function (): void {
    $autre = Association::factory()->create();
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $autre->id,
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $autre->id]);

    BudgetLine::factory()->create([
        'association_id' => $autre->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    expect(app(RapportService::class)->operationsEligibles(2025, avecBudget: true))
        ->not->toContain((int) $operation->id);
});

it('une ventilation d un autre exercice n eligibilise pas l operation', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2024,
        'montant_prevu' => 300.00,
    ]);

    expect(app(RapportService::class)->operationsEligibles(2025, avecBudget: true))
        ->not->toContain((int) $operation->id);
});
