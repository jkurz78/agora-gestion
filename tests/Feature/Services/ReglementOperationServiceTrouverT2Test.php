<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->ecritureGen = app(EcritureGenerator::class);
    $this->service = app(ReglementOperationService::class);

    // Compte 401 système
    $this->compte401 = compteSysteme('401');
    $this->compte411 = compteSysteme('411');
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Test 1 — trouverT2 trouve la T2 pour une recette via lettrage 411
// ---------------------------------------------------------------------------

it('[1] trouverT2 finds T2 for recette via 411 lettrage', function () {
    // T1 : créance recette avec 411 D
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture test T1',
    );

    // T2 : encaissement créance — lettrage 411 T1 ↔ T2
    $t2 = $this->ecritureGen->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Encaissement test',
    );

    $found = $this->service->trouverT2($t1);

    expect($found)->not->toBeNull();
    expect((int) $found->id)->toBe((int) $t2->id);
});

// ---------------------------------------------------------------------------
// Test 2 — trouverT2 trouve la T2 pour une dépense via lettrage 401
// ---------------------------------------------------------------------------

it('[2] trouverT2 finds T2 for dépense via 401 lettrage', function () {
    // T1 : dette dépense avec 401 C
    $t1 = $this->ecritureGen->pourDepenseACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte606, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture fournisseur T1',
    );

    // T2 : règlement fournisseur — lettrage 401 T1 ↔ T2
    $t2 = $this->ecritureGen->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Règlement fournisseur test',
    );

    $found = $this->service->trouverT2($t1);

    expect($found)->not->toBeNull();
    expect((int) $found->id)->toBe((int) $t2->id);
});

// ---------------------------------------------------------------------------
// Test 3 — trouverT2 retourne null si aucune ligne tiers lettrée
// ---------------------------------------------------------------------------

it('[3] trouverT2 returns null if no lettrée tiers line', function () {
    // T1 : créance recette avec 411 D — non lettrée (pas de T2)
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance ouverte',
    );

    // Précondition : ligne 411 non lettrée
    $ligne411 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', (int) $this->compte411->id)
        ->firstOrFail();
    expect($ligne411->lettrage_code)->toBeNull();

    $found = $this->service->trouverT2($t1);

    expect($found)->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 4 — trouverT2 retourne null si cas lumpé (contrepartie 411 sur même tx)
// ---------------------------------------------------------------------------

it('[4] trouverT2 returns null if lumped (contrepartie 411 sur même tx)', function () {
    // Recette comptant : paire 411 D ↔ C lettrée sur la MÊME transaction (lumpée)
    $t1 = $this->ecritureGen->pourRecetteComptant(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 120.0]],
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        date: new DateTimeImmutable('2025-11-01'),
        libelle: 'Recette comptant lumpée',
    );

    // Précondition : les 2 lignes 411 de T1 sont lettrées ensemble sur la même tx
    $lignes411 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', (int) $this->compte411->id)
        ->get();
    expect($lignes411)->toHaveCount(2);
    expect($lignes411[0]->lettrage_code)->not->toBeNull();
    expect($lignes411[0]->lettrage_code)->toBe($lignes411[1]->lettrage_code);

    $found = $this->service->trouverT2($t1);

    // Cas lumpé → null (pas de T2 séparée)
    expect($found)->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 5 — trouverT2 fonctionne sur miroir extourne recette (411 C lettrée)
// ---------------------------------------------------------------------------

it('[5] trouverT2 works on extourne mirror recette (411 C lettrée)', function () {
    // T1 : créance recette avec 411 D
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 180.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance extourne test',
    );

    // T2 : encaissement créance — lettrage 411 T1 ↔ T2
    $t2 = $this->ecritureGen->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Encaissement pour extourne',
    );

    // Créer manuellement un miroir extourne : copie de T2 avec lignes 411/512X inversées.
    // Le miroir a une ligne 411 D (inversée depuis C du T2) lettrée vers une nouvelle T3.
    // Simulation : crée une transaction miroir + nouvelle T3 (contre-encaissement),
    // puis lettrage miroir.411 ↔ T3.411.

    $miroir = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'montant_total' => -180.0,
        'statut_reglement' => StatutReglement::Recu,
        'type_ecriture' => 'extourne',
    ]);

    // T3 : transaction contre-encaissement (représente la T2 du miroir)
    $t3 = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'montant_total' => 180.0,
        'statut_reglement' => StatutReglement::Recu,
    ]);

    // Lettrage miroir.411 C ↔ T3.411 D
    $codeLettrage = 'EX-TEST-411-01';

    // Ligne 411 sur miroir : Credit (inversée) lettrée
    TransactionLigne::create([
        'transaction_id' => (int) $miroir->id,
        'compte_id' => (int) $this->compte411->id,
        'tiers_id' => (int) $this->tiers->id,
        'debit' => 0.0,
        'credit' => 180.0,
        'montant' => 180.0,
        'lettrage_code' => $codeLettrage,
    ]);

    // Ligne 411 sur T3 : Debit lettrée (la T2 de ce miroir)
    TransactionLigne::create([
        'transaction_id' => (int) $t3->id,
        'compte_id' => (int) $this->compte411->id,
        'tiers_id' => (int) $this->tiers->id,
        'debit' => 180.0,
        'credit' => 0.0,
        'montant' => 180.0,
        'lettrage_code' => $codeLettrage,
    ]);

    // trouverT2 sur le miroir doit trouver T3 (agnostique au sens D/C)
    $found = $this->service->trouverT2($miroir);

    expect($found)->not->toBeNull();
    expect((int) $found->id)->toBe((int) $t3->id);
});

// ---------------------------------------------------------------------------
// Test 6 — trouverT2 fonctionne sur miroir extourne dépense (401 D lettrée)
// ---------------------------------------------------------------------------

it('[6] trouverT2 works on extourne mirror dépense (401 D lettrée)', function () {
    // T1 : dette dépense avec 401 C
    $t1 = $this->ecritureGen->pourDepenseACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte606, 'montant' => 240.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Dette fournisseur extourne test',
    );

    // T2 : règlement fournisseur — lettrage 401 T1 ↔ T2
    $t2 = $this->ecritureGen->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Règlement pour extourne',
    );

    // Créer manuellement un miroir extourne pour la dépense.
    // Le miroir a une ligne 401 C (inversée depuis D du T2) lettrée vers une T3.
    $miroir = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Depense,
        'montant_total' => -240.0,
        'statut_reglement' => StatutReglement::Recu,
        'type_ecriture' => 'extourne',
    ]);

    $t3 = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Depense,
        'montant_total' => 240.0,
        'statut_reglement' => StatutReglement::Recu,
    ]);

    $codeLettrage = 'EX-TEST-401-01';

    // Ligne 401 sur miroir : Debit (inversée depuis Credit du T2) lettrée
    TransactionLigne::create([
        'transaction_id' => (int) $miroir->id,
        'compte_id' => (int) $this->compte401->id,
        'tiers_id' => (int) $this->tiers->id,
        'debit' => 240.0,
        'credit' => 0.0,
        'montant' => 240.0,
        'lettrage_code' => $codeLettrage,
    ]);

    // Ligne 401 sur T3 : Credit lettrée (la T2 de ce miroir)
    TransactionLigne::create([
        'transaction_id' => (int) $t3->id,
        'compte_id' => (int) $this->compte401->id,
        'tiers_id' => (int) $this->tiers->id,
        'debit' => 0.0,
        'credit' => 240.0,
        'montant' => 240.0,
        'lettrage_code' => $codeLettrage,
    ]);

    // trouverT2 sur le miroir doit trouver T3 (agnostique au sens D/C)
    $found = $this->service->trouverT2($miroir);

    expect($found)->not->toBeNull();
    expect((int) $found->id)->toBe((int) $t3->id);
});
