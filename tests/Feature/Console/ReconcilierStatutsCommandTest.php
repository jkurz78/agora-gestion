<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('détecte une divergence miroir↔ledger en --check (exit non nul)', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Divergence',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    // Corrompre le miroir (le dérivé serait EnMain pour un chèque en main).
    $t1->forceFill(['statut_reglement' => StatutReglement::Pointe->value])->save();

    $this->artisan('compta:reconcilier-statuts', ['--check' => true])
        ->assertExitCode(1);
});

it('corrige les divergences sans --check (exit 0, miroir resynchronisé)', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'À corriger',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $t1->forceFill(['statut_reglement' => StatutReglement::Pointe->value])->save();

    $this->artisan('compta:reconcilier-statuts')->assertExitCode(0);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('ne signale rien quand le miroir est aligné (exit 0)', function () {
    app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Aligné',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $this->artisan('compta:reconcilier-statuts', ['--check' => true])->assertExitCode(0);
});
