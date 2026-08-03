<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Enums\StatutReglement;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

test('reglerOuEncaisser relit la transaction en base et verrouille l exercice avant le poste tiers', function () {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => '42.00']],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance wrapper avec modèle périmé',
    );
    $transactionPerimee = $t1->fresh();

    Transaction::query()
        ->whereKey((int) $t1->id)
        ->update([
            'mode_paiement' => ModePaiement::Virement->value,
            'compte_id' => (int) $this->compteBancaire->id,
        ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->service->reglerOuEncaisser($transactionPerimee);

    $requetes = collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (string $sql): string => strtolower(str_replace(['"', '`'], '', $sql)))
        ->values();
    DB::disableQueryLog();
    $indexExercice = $requetes->search(
        fn (string $sql): bool => str_contains($sql, 'from exercices')
    );
    $indexLot = $requetes->search(
        fn (string $sql): bool => str_contains($sql, 'from transaction_lignes')
            && str_contains($sql, 'poste_tiers_parent_id')
    );

    expect($indexExercice)->not->toBeFalse()
        ->and($indexLot)->not->toBeFalse()
        ->and((int) $indexExercice)->toBeLessThan((int) $indexLot)
        ->and(
            $t1->lignes()
                ->whereNotNull('lettrage_code')
                ->exists()
        )->toBeTrue();
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

test('marquerRecu — wrapper historique délègue le solde agrégé à la date de la transaction', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-03'),
        libelle: 'Créance fractionnée avant wrapper',
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente]);
    $ligne411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $ligne411->update(['debit' => '60.00']);
    $fractionOuverte = $ligne411->replicate(['id', 'lettrage_code', 'deleted_at']);
    $fractionOuverte->fill([
        'debit' => '40.00',
        'credit' => '0.00',
        'poste_tiers_parent_id' => (int) $ligne411->id,
    ]);
    $fractionOuverte->save();

    $this->service->marquerRecu(
        $t1->fresh(),
        ModePaiement::Virement,
        (int) $this->compteBancaire->id,
    );

    $reglement = app(PostesTiersOuvertsService::class)->reglements($t1->fresh())->sole();
    $t2 = Transaction::findOrFail($reglement->transactionId);

    expect($reglement->montantCentimes)->toBe(10000)
        ->and($t2->date->toDateString())->toBe('2025-10-03')
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu)
        ->and(app(PostesTiersOuvertsService::class)->pourTransaction($t1->fresh(), 2025))->toBeNull();
});

test('marquerRecu — wrapper historique date un règlement AN au jour de la pièce active', function () {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 80.0]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance reportée avant wrapper',
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente]);
    $source411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $generation = app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $this->user,
    );
    $ligneAN = $generation->origines()
        ->with('ligneAN')
        ->where('ligne_racine_id', (int) $source411->id)
        ->firstOrFail()
        ->ligneAN;

    $this->service->marquerRecu(
        $t1->fresh(),
        ModePaiement::Virement,
        (int) $this->compteBancaire->id,
    );

    $reglement = app(PostesTiersOuvertsService::class)->reglements($t1->fresh())->sole();
    $t2 = Transaction::findOrFail($reglement->transactionId);

    expect($source411->fresh()->lettrage_code)->toBeNull()
        ->and($ligneAN->fresh()->lettrage_code)->not->toBeNull()
        ->and($t2->date->toDateString())->toBe('2026-09-01')
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

test('les wrappers refusent un compte bancaire étranger avant de créer ou modifier une écriture', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => '25.00']],
        dateConstatation: new DateTimeImmutable('2025-10-03'),
        libelle: 'Créance compte étranger',
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente]);
    $associationLocale = $this->association;
    $associationEtrangere = Association::factory()->create();
    TenantContext::boot($associationEtrangere);
    $compteEtranger = CompteBancaire::factory()->create([
        'association_id' => $associationEtrangere->id,
    ]);
    TenantContext::boot($associationLocale);
    $transactionsAvant = Transaction::withoutGlobalScopes()->count();

    expect(fn () => $this->service->marquerRecu(
        $t1->fresh(),
        ModePaiement::Cheque,
        (int) $compteEtranger->id,
    ))->toThrow(ModelNotFoundException::class);

    expect($t1->fresh()->mode_paiement)->toBeNull()
        ->and($t1->fresh()->compte_id)->toBeNull()
        ->and(Transaction::withoutGlobalScopes()->count())->toBe($transactionsAvant);

    $t1->update([
        'mode_paiement' => ModePaiement::Cheque,
        'compte_id' => (int) $compteEtranger->id,
    ]);

    expect(fn () => $this->service->reglerOuEncaisser($t1->fresh()))
        ->toThrow(ModelNotFoundException::class);

    expect(Transaction::withoutGlobalScopes()->count())->toBe($transactionsAvant);
});

test('le service wrapper refuse une transaction source étrangère au poste tiers', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1A = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => '50.00']],
        dateConstatation: new DateTimeImmutable('2025-10-03'),
        libelle: 'Créance A',
    );
    $t1B = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => '60.00']],
        dateConstatation: new DateTimeImmutable('2025-10-03'),
        libelle: 'Créance B',
    );
    $t1A->update(['statut_reglement' => StatutReglement::EnAttente]);
    $t1B->update(['statut_reglement' => StatutReglement::EnAttente]);
    $ligneA = $t1A->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $ligneB = $t1B->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $transactionsAvant = Transaction::count();

    expect(fn () => app(PosteTiersReglementService::class)->reglerReliquatEtRenseignerTransaction(
        ligneId: (int) $ligneA->id,
        transactionSourceId: (int) $t1B->id,
        date: CarbonImmutable::parse('2025-10-03'),
        modePropose: ModePaiement::Virement,
        compteBancaireIdPropose: (int) $this->compteBancaire->id,
        exercice: 2025,
    ))->toThrow(DomainException::class, 'transaction source ne correspond pas au poste tiers');

    expect(Transaction::count())->toBe($transactionsAvant)
        ->and($ligneA->fresh()->lettrage_code)->toBeNull()
        ->and($ligneA->fresh()->fractionsPosteTiers()->count())->toBe(0)
        ->and($ligneB->fresh()->lettrage_code)->toBeNull()
        ->and($t1B->fresh()->mode_paiement)->toBeNull()
        ->and($t1B->fresh()->compte_id)->toBeNull();
});

test('marquerRecu relit le reliquat courant après un règlement partiel concurrent', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => '100.00']],
        dateConstatation: new DateTimeImmutable('2025-10-03'),
        libelle: 'Créance reliquat frais',
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente]);
    $ligne411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );

    app(PosteTiersReglementService::class)->regler(new PosteTiersReglementData(
        ligneId: (int) $ligne411->id,
        montantCentimes: 3000,
        date: CarbonImmutable::parse('2025-10-03'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    ));

    $this->service->marquerRecu(
        $t1,
        ModePaiement::Virement,
        (int) $this->compteBancaire->id,
    );

    expect(app(PostesTiersOuvertsService::class)->reglements($t1->fresh())
        ->pluck('montantCentimes')
        ->sort()
        ->values()
        ->all())->toBe([3000, 7000])
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

test('marquerRecu annule T2 et le lettrage si la mise à jour du mode de T1 échoue', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => '73.00']],
        dateConstatation: new DateTimeImmutable('2025-10-03'),
        libelle: 'Créance rollback wrapper',
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente]);
    $ligne411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $transactionsAvant = Transaction::count();
    DB::statement(
        "CREATE TRIGGER echec_mode_t1
        BEFORE UPDATE OF mode_paiement ON transactions
        WHEN OLD.mode_paiement IS NULL AND NEW.mode_paiement IS NOT NULL
        BEGIN
            SELECT RAISE(ABORT, 'Échec sentinelle mise à jour mode T1');
        END"
    );

    try {
        expect(fn () => $this->service->marquerRecu(
            $t1->fresh(),
            ModePaiement::Virement,
            (int) $this->compteBancaire->id,
        ))->toThrow(QueryException::class, 'Échec sentinelle');
    } finally {
        DB::statement('DROP TRIGGER echec_mode_t1');
    }

    expect(Transaction::count())->toBe($transactionsAvant)
        ->and($ligne411->fresh()->lettrage_code)->toBeNull()
        ->and($ligne411->fractionsPosteTiers()->count())->toBe(0)
        ->and($t1->fresh()->mode_paiement)->toBeNull()
        ->and($t1->fresh()->compte_id)->toBeNull()
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});
