<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->ecritureGen = app(EcritureGenerator::class);
    $this->service = app(ReglementOperationService::class);
});

afterEach(function () {
    TenantContext::clear();
});

test('reglerOuEncaisser — recette normale crée T2 encaissement via pourReglement', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance test',
    );
    // Set mode_paiement + compte_id (required for T2 generation)
    $t1->update(['mode_paiement' => ModePaiement::Virement->value, 'compte_id' => $this->compteBancaire->id]);
    $t1->refresh();

    $this->service->reglerOuEncaisser($t1);

    // A T2 should have been created
    $ligne411T1 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', (int) Compte::ofNumeroSysteme('411')->id)
        ->first();
    expect($ligne411T1->lettrage_code)->not->toBeNull();

    // Find T2 via lettrage
    $ligneT2 = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
        ->where('transaction_id', '!=', (int) $t1->id)
        ->first();
    expect($ligneT2)->not->toBeNull();
});

test('reglerOuEncaisser — dépense normale crée T2 règlement via pourReglement', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $t1 = $this->ecritureGen->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte606, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Dette test',
    );
    $t1->update(['mode_paiement' => ModePaiement::Virement->value, 'compte_id' => $this->compteBancaire->id]);
    $t1->refresh();

    $this->service->reglerOuEncaisser($t1);

    $ligne401T1 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', (int) Compte::ofNumeroSysteme('401')->id)
        ->first();
    expect($ligne401T1->lettrage_code)->not->toBeNull();
});

test('reglerOuEncaisser — no-op si mode_paiement null', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    // mode_paiement stays null (not set)

    $this->service->reglerOuEncaisser($t1);

    // 411 should still be unlettered
    $ligne411 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', (int) Compte::ofNumeroSysteme('411')->id)
        ->first();
    expect($ligne411->lettrage_code)->toBeNull();
});

test('reglerOuEncaisser — no-op si ligne tiers déjà lettrée (idempotence)', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 120.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $t1->update(['mode_paiement' => ModePaiement::Virement->value, 'compte_id' => $this->compteBancaire->id]);
    $t1->refresh();

    // First call creates T2
    $this->service->reglerOuEncaisser($t1);

    $txCountBefore = Transaction::count();

    // Second call should be no-op (idempotent)
    $this->service->reglerOuEncaisser($t1->fresh());

    expect(Transaction::count())->toBe($txCountBefore);
});

test('marquerRegle — recette EnAttente → statut dérivé + T2', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 180.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    // Ensure it's EnAttente (default from pourRecetteACredit is factory-dependent)
    $t1->update(['statut_reglement' => StatutReglement::EnAttente->value]);
    $t1->refresh();

    $this->service->marquerRegle($t1, ModePaiement::Virement, (int) $this->compteBancaire->id);

    $t1Fresh = $t1->fresh();
    // Mode should be set
    expect($t1Fresh->mode_paiement)->toBe(ModePaiement::Virement);
    // Statut should NOT be EnAttente anymore (derived by EtatReglementResolver)
    expect($t1Fresh->statut_reglement)->not->toBe(StatutReglement::EnAttente);
    // 411 should be lettered (T2 created)
    $ligne411 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', (int) Compte::ofNumeroSysteme('411')->id)
        ->first();
    expect($ligne411->lettrage_code)->not->toBeNull();
});

test('marquerRegle — dépense EnAttente → statut dérivé + T2', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $t1 = $this->ecritureGen->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte606, 'montant' => 220.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente->value]);
    $t1->refresh();

    $this->service->marquerRegle($t1, ModePaiement::Virement, (int) $this->compteBancaire->id);

    $t1Fresh = $t1->fresh();
    expect($t1Fresh->mode_paiement)->toBe(ModePaiement::Virement);
    expect($t1Fresh->statut_reglement)->not->toBe(StatutReglement::EnAttente);
    $ligne401 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', (int) Compte::ofNumeroSysteme('401')->id)
        ->first();
    expect($ligne401->lettrage_code)->not->toBeNull();
});
