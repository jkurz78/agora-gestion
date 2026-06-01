<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Association;
use App\Models\Transaction;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('le total recettes du flux de trésorerie exclut le journal de banque', function () {
    $assoId = (int) TenantContext::currentId();
    // Exercice 2025 = 1er sept 2025 → 31 août 2026
    Transaction::factory()->asRecette()->create([
        'association_id' => $assoId,
        'journal' => JournalComptable::Vente,
        'montant_total' => 100,
        'date' => '2025-10-01',
    ]);
    Transaction::factory()->asRecette()->create([
        'association_id' => $assoId,
        'journal' => JournalComptable::Banque,
        'montant_total' => 80,
        'date' => '2025-10-02',
    ]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    // Le journal de banque (80) ne doit PAS être comptabilisé ; seule la vente (100) compte.
    expect($flux['synthese']['total_recettes'])->toBe(100.0);
});

it('la ventilation mensuelle du flux de trésorerie exclut le journal de banque', function () {
    $assoId = (int) TenantContext::currentId();
    // Exercice 2025 = 1er sept 2025 → 31 août 2026 ; octobre = mois index 1 (annee-mois 2025-10)
    Transaction::factory()->asRecette()->create([
        'association_id' => $assoId,
        'journal' => JournalComptable::Vente,
        'montant_total' => 100,
        'date' => '2025-10-01',
    ]);
    Transaction::factory()->asRecette()->create([
        'association_id' => $assoId,
        'journal' => JournalComptable::Banque,
        'montant_total' => 80,
        'date' => '2025-10-02',
    ]);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie(2025);

    // $flux['mensuel'] est indexé de 0 à 11 ; octobre = index 1 (sept=0, oct=1)
    $octobre = $flux['mensuel'][1];
    // Seule la vente (100) doit apparaître dans le mensuel, pas le banque (80)
    expect($octobre['recettes'])->toBe(100.0, 'La ventilation mensuelle ne doit pas inclure le journal banque');
});
