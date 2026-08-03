<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->ecritureGen = app(EcritureGenerator::class);
});

// ---------------------------------------------------------------------------
// Test 1 : recette normale (411 D) → portage D / 411 C + lettrage
// ---------------------------------------------------------------------------

test('pourReglement — recette normale (411 D) → portage D / 411 C + lettrage', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture test',
    );

    $t2 = $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
    );

    expect($t2)->not->toBeNull();
    expect((float) $t2->montant_total)->toBe(150.0);

    $lignes = $t2->lignes;
    expect($lignes)->toHaveCount(2);

    $compte411Id = (int) compteSysteme('411')->id;
    $lignePortage = $lignes->first(fn ($l) => (int) $l->compte_id !== $compte411Id);
    $ligneTiers = $lignes->first(fn ($l) => (int) $l->compte_id === $compte411Id);

    expect((float) $lignePortage->debit)->toBe(150.0);
    expect((float) $lignePortage->credit)->toBe(0.0);
    expect((float) $ligneTiers->debit)->toBe(0.0);
    expect((float) $ligneTiers->credit)->toBe(150.0);

    // Lettrage T1 ↔ T2 sur 411 (recharger depuis DB — lettrer() met à jour la DB, pas les objets en mémoire)
    $ligneTiersT2Fresh = $ligneTiers->fresh();
    $ligneT1_411 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', $compte411Id)
        ->first();
    expect($ligneTiersT2Fresh->lettrage_code)->not->toBeNull();
    expect($ligneT1_411->lettrage_code)->toBe($ligneTiersT2Fresh->lettrage_code);
});

// ---------------------------------------------------------------------------
// Test 2 : dépense normale (401 C) → 401 D / portage C + lettrage
// ---------------------------------------------------------------------------

test('pourReglement — dépense normale (401 C) → 401 D / portage C + lettrage', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte606, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture fournisseur',
    );

    $t2 = $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
    );

    expect($t2)->not->toBeNull();
    expect((float) $t2->montant_total)->toBe(200.0);

    $lignes = $t2->lignes;
    expect($lignes)->toHaveCount(2);

    $compte401Id = (int) compteSysteme('401')->id;
    $ligneTiers = $lignes->first(fn ($l) => (int) $l->compte_id === $compte401Id);
    $lignePortage = $lignes->first(fn ($l) => (int) $l->compte_id !== $compte401Id);

    expect((float) $ligneTiers->debit)->toBe(200.0);
    expect((float) $ligneTiers->credit)->toBe(0.0);
    expect((float) $lignePortage->debit)->toBe(0.0);
    expect((float) $lignePortage->credit)->toBe(200.0);

    // Lettrage T1 ↔ T2 sur 401 (recharger depuis DB)
    $ligneTiersT2Fresh = $ligneTiers->fresh();
    $ligneT1_401 = TransactionLigne::where('transaction_id', (int) $t1->id)
        ->where('compte_id', $compte401Id)
        ->first();
    expect($ligneTiersT2Fresh->lettrage_code)->not->toBeNull();
    expect($ligneT1_401->lettrage_code)->toBe($ligneTiersT2Fresh->lettrage_code);
});

// ---------------------------------------------------------------------------
// Test 3 : miroir recette extourne (411 C) → 411 D / portage C + lettrage
// ---------------------------------------------------------------------------

test('pourReglement — miroir recette extourne (411 C) → 411 D / portage C + lettrage', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $compte411 = compteSysteme('411');

    // Miroir d'extourne simulé : T1 recette avec 411 C (sens inversé)
    $miroir = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'montant_total' => 180.0,
        'mode_paiement' => null,
        'statut_reglement' => StatutReglement::EnAttente,
        'type_ecriture' => 'extourne',
    ]);

    // Ligne tiers 411 C (ouverte — crédit = argent sortant)
    TransactionLigne::create([
        'transaction_id' => (int) $miroir->id,
        'compte_id' => (int) $compte411->id,
        'debit' => 0,
        'credit' => 180.0,
        'tiers_id' => (int) $tiers->id,
        'libelle' => 'Miroir extourne 411 C',
        'montant' => 0,
    ]);

    // Ligne d'équilibre 706 D
    TransactionLigne::create([
        'transaction_id' => (int) $miroir->id,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 180.0,
        'credit' => 0,
        'tiers_id' => null,
        'libelle' => 'Miroir extourne 706 D',
        'montant' => 180.0,
    ]);

    $t2 = $this->ecritureGen->pourReglement(
        t1: $miroir,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-20'),
    );

    // T2 hérite du type T1 (Recette)
    expect($t2->type)->toBe(TypeTransaction::Recette);
    expect((float) $t2->montant_total)->toBe(180.0);

    $lignes = $t2->lignes;
    expect($lignes)->toHaveCount(2);

    // 411 C source → argent sortant → T2 : 411 D / portage C
    $compte411Id = (int) $compte411->id;
    $ligneTiers = $lignes->first(fn ($l) => (int) $l->compte_id === $compte411Id);
    $lignePortage = $lignes->first(fn ($l) => (int) $l->compte_id !== $compte411Id);

    expect((float) $ligneTiers->debit)->toBe(180.0);
    expect((float) $ligneTiers->credit)->toBe(0.0);
    expect((float) $lignePortage->debit)->toBe(0.0);
    expect((float) $lignePortage->credit)->toBe(180.0);

    // Lettrage : ligne miroir 411 C ↔ T2 411 D (recharger depuis DB)
    expect($ligneTiers->fresh()->lettrage_code)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 4 : miroir dépense extourne (401 D) → portage D / 401 C + lettrage
// ---------------------------------------------------------------------------

test('pourReglement — miroir dépense extourne (401 D) → portage D / 401 C + lettrage', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $compte401 = compteSysteme('401');

    // Miroir d'extourne simulé : T1 dépense avec 401 D (sens inversé)
    $miroir = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Depense,
        'montant_total' => 240.0,
        'mode_paiement' => null,
        'statut_reglement' => StatutReglement::EnAttente,
        'type_ecriture' => 'extourne',
    ]);

    // Ligne tiers 401 D (ouverte — débit = argent entrant)
    TransactionLigne::create([
        'transaction_id' => (int) $miroir->id,
        'compte_id' => (int) $compte401->id,
        'debit' => 240.0,
        'credit' => 0,
        'tiers_id' => (int) $tiers->id,
        'libelle' => 'Miroir extourne 401 D',
        'montant' => 0,
    ]);

    // Ligne d'équilibre 606 C
    TransactionLigne::create([
        'transaction_id' => (int) $miroir->id,
        'compte_id' => (int) $this->compte606->id,
        'debit' => 0,
        'credit' => 240.0,
        'tiers_id' => null,
        'libelle' => 'Miroir extourne 606 C',
        'montant' => 240.0,
    ]);

    $t2 = $this->ecritureGen->pourReglement(
        t1: $miroir,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-20'),
    );

    // T2 hérite du type T1 (Depense)
    expect($t2->type)->toBe(TypeTransaction::Depense);
    expect((float) $t2->montant_total)->toBe(240.0);

    $lignes = $t2->lignes;
    expect($lignes)->toHaveCount(2);

    // 401 D source → argent entrant → T2 : portage D / 401 C
    $compte401Id = (int) $compte401->id;
    $ligneTiers = $lignes->first(fn ($l) => (int) $l->compte_id === $compte401Id);
    $lignePortage = $lignes->first(fn ($l) => (int) $l->compte_id !== $compte401Id);

    expect((float) $ligneTiers->debit)->toBe(0.0);
    expect((float) $ligneTiers->credit)->toBe(240.0);   // 401 C
    expect((float) $lignePortage->debit)->toBe(240.0);  // portage D
    expect((float) $lignePortage->credit)->toBe(0.0);

    // Chèque entrant → resoudreComptePortage → 5112
    expect($lignePortage->compte->numero_pcg)->toBe('5112');

    // Lettrage (recharger depuis DB)
    expect($ligneTiers->fresh()->lettrage_code)->not->toBeNull();
});

test('pourReglement — cible explicitement une fraction ouverte de la meme racine', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture fractionnée',
    );
    $premiereLigne = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $secondeLigne = TransactionLigne::create([
        'transaction_id' => (int) $t1->id,
        'compte_id' => (int) $premiereLigne->compte_id,
        'debit' => 35.0,
        'credit' => 0,
        'tiers_id' => (int) $tiers->id,
        'poste_tiers_parent_id' => (int) $premiereLigne->id,
        'libelle' => 'Seconde fraction 411',
        'montant' => 0,
    ]);

    $t2 = $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-07-23'),
        ligneTiersSource: $secondeLigne,
    );

    expect($secondeLigne->fresh()->lettrage_code)->not->toBeNull()
        ->and($premiereLigne->fresh()->lettrage_code)->toBeNull()
        ->and($t2->date->toDateString())->toBe('2026-07-23')
        ->and((float) $t2->montant_total)->toBe(35.0);
});

test('pourReglement — refuse une ligne explicite issue d une autre racine metier', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $autreT1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 35.0]],
        dateConstatation: new DateTimeImmutable('2025-10-02'),
    );
    $ligneAutreRacine = $autreT1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );

    expect(fn () => $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-07-23'),
        ligneTiersSource: $ligneAutreRacine,
    ))->toThrow(InvalidArgumentException::class);
});

test('pourReglement — refuse une ligne explicite dont la transaction appartient a un autre tenant', function () {
    $tiersLocal = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiersLocal,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $ligneRacineLocale = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );

    $associationEtrangere = Association::factory()->create();
    TenantContext::boot($associationEtrangere);

    $transactionEtrangere = Transaction::create([
        'association_id' => (int) $associationEtrangere->id,
        'type' => TypeTransaction::Recette,
        'date' => '2025-10-02',
        'libelle' => 'Transaction étrangère',
        'montant_total' => 35.0,
        'mode_paiement' => null,
        'statut_reglement' => StatutReglement::EnAttente,
        'equilibree' => true,
        'type_ecriture' => 'normale',
    ]);
    $ligneEtrangere = TransactionLigne::create([
        'transaction_id' => (int) $transactionEtrangere->id,
        'compte_id' => (int) $ligneRacineLocale->compte_id,
        'debit' => 35.0,
        'credit' => 0,
        'tiers_id' => (int) $tiersLocal->id,
        'poste_tiers_parent_id' => (int) $ligneRacineLocale->id,
        'libelle' => 'Fraction étrangère maquillée en poste local',
        'montant' => 0,
    ]);

    TenantContext::boot($this->association);

    $ligneEtrangereHorsScope = TransactionLigne::withoutGlobalScopes()
        ->findOrFail((int) $ligneEtrangere->id);
    $nombreTransactionsAvant = Transaction::withoutGlobalScopes()->count();

    expect(fn () => $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-07-23'),
        ligneTiersSource: $ligneEtrangereHorsScope,
    ))->toThrow(TenantBoundaryException::class);

    expect(Transaction::withoutGlobalScopes()->count())->toBe($nombreTransactionsAvant)
        ->and(
            TransactionLigne::withoutGlobalScopes()
                ->findOrFail((int) $ligneEtrangere->id)
                ->lettrage_code
        )->toBeNull()
        ->and($ligneRacineLocale->fresh()->lettrage_code)->toBeNull();
});

test('pourReglement — refuse une ligne explicite deja lettree', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $ligneLettree = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $ligneLettree->update(['lettrage_code' => 'LETTRAGE-DEJA-PRESENT']);

    expect(fn () => $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-07-23'),
        ligneTiersSource: $ligneLettree,
    ))->toThrow(InvalidArgumentException::class);
});

test('pourReglement — refuse une ligne explicite sans tiers', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $ligneRacine = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $ligneSansTiers = TransactionLigne::create([
        'transaction_id' => (int) $t1->id,
        'compte_id' => (int) $ligneRacine->compte_id,
        'debit' => 35.0,
        'credit' => 0,
        'tiers_id' => null,
        'poste_tiers_parent_id' => (int) $ligneRacine->id,
        'libelle' => 'Fraction sans tiers',
        'montant' => 0,
    ]);

    expect(fn () => $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-07-23'),
        ligneTiersSource: $ligneSansTiers,
    ))->toThrow(InvalidArgumentException::class);
});

test('pourReglement — refuse une ligne explicite hors compte 401 ou 411', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $ligneRacine = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $ligneProduit = TransactionLigne::create([
        'transaction_id' => (int) $t1->id,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 35.0,
        'credit' => 0,
        'tiers_id' => (int) $tiers->id,
        'poste_tiers_parent_id' => (int) $ligneRacine->id,
        'libelle' => 'Fraction hors compte tiers',
        'montant' => 0,
    ]);

    expect(fn () => $this->ecritureGen->pourReglement(
        t1: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-07-23'),
        ligneTiersSource: $ligneProduit,
    ))->toThrow(InvalidArgumentException::class);
});
