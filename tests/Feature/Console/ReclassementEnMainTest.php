<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\Compta\EtatReglementResolver;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('reclasse une recette chèque non remise (Recu périmé) → EnMain via resolver', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Chèque en main',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    // Simuler l'état pré-chantier-4 : colonne = Recu (le chèque comptant naissait Recu).
    $t1->forceFill(['statut_reglement' => StatutReglement::Recu->value])->save();

    // La data-migration recalcule via le resolver.
    app(EtatReglementResolver::class)->syncer($t1->fresh());

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('ne touche pas une recette virement déjà dénouée (512X) — reste Recu', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Virement déjà en banque',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    app(EtatReglementResolver::class)->syncer($t1->fresh());

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
