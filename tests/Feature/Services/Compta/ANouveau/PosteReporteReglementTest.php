<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Enums\StatutReglement;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

    $this->acteurReport = User::factory()->create();
    $this->acteurReport->associations()->attach(TenantContext::currentId(), [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
});

function compteReportAN(string $numero, int $classe): Compte
{
    return Compte::create([
        'numero_pcg' => $numero,
        'intitule' => 'Compte report '.$numero,
        'classe' => $classe,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

function genererReportAN(User $acteur): ANouveauGeneration
{
    return app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $acteur,
    );
}

it('lettre le descendant AN d une creance 411 encaissee en N plus 1', function (): void {
    $tiers = Tiers::factory()->create();
    $produit = compteReportAN('706-R', 7);
    $banque = compteReportAN('512-R', 5);
    $generator = app(EcritureGenerator::class);

    $t1 = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $produit, 'montant' => 80.00]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance à reporter',
    );
    $source411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $generation = genererReportAN($this->acteurReport);
    $ligneAN = $generation->origines()->with('ligneAN.compte')->firstOrFail()->ligneAN;
    $postesOuverts = app(PostesTiersOuvertsService::class);

    expect($postesOuverts->soldeActifPourTransaction($t1))->toBe(8000);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $banque,
        datePaiement: new DateTimeImmutable('2026-09-05'),
    );
    $ligne411T2 = $t2->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );

    expect($source411->fresh()->lettrage_code)->toBeNull()
        ->and($ligneAN->fresh()->lettrage_code)->not->toBeNull()
        ->and($ligneAN->fresh()->lettrage_code)->toBe($ligne411T2->fresh()->lettrage_code)
        ->and(app(ReglementOperationService::class)->trouverT2($t1)?->is($t2))->toBeTrue()
        ->and(app(EtatReglementResolver::class)->resolve($t1))->toBe(StatutReglement::Recu)
        ->and($postesOuverts->soldeActifPourTransaction($t1))->toBe(0)
        ->and($postesOuverts->reglements($t1)->sole()->transactionId)->toBe((int) $t2->id);
});

it('lettre le descendant AN d une dette 401 reglee en N plus 1', function (): void {
    $tiers = Tiers::factory()->create();
    $charge = compteReportAN('606-R', 6);
    $banque = compteReportAN('512-F', 5);
    $generator = app(EcritureGenerator::class);

    $t1 = $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $charge, 'montant' => 45.50]],
        dateConstatation: new DateTimeImmutable('2026-08-21'),
        libelle: 'Dette à reporter',
    );
    $source401 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '401'
    );
    $generation = genererReportAN($this->acteurReport);
    $ligneAN = $generation->origines()->with('ligneAN.compte')->firstOrFail()->ligneAN;

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $banque,
        datePaiement: new DateTimeImmutable('2026-09-06'),
    );
    $ligne401T2 = $t2->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '401'
    );

    expect($source401->fresh()->lettrage_code)->toBeNull()
        ->and($ligneAN->fresh()->lettrage_code)->not->toBeNull()
        ->and($ligneAN->fresh()->lettrage_code)->toBe($ligne401T2->fresh()->lettrage_code)
        ->and(app(ReglementOperationService::class)->trouverT2($t1)?->is($t2))->toBeTrue()
        ->and(app(EtatReglementResolver::class)->resolve($t1))->toBe(StatutReglement::Recu);
});

it('ne reprend plus en N plus 2 un reliquat AN réglé en N plus 1', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);
    $tiers = Tiers::factory()->create();
    $produit = compteReportAN('706-RELIQUAT', 7);
    $compteBancaire = CompteBancaire::factory()->create([
        'iban' => 'FR7612345000012345678901234',
    ]);
    BancairesSeeder::seed();
    $generator = app(EcritureGenerator::class);
    $reglements = app(PosteTiersReglementService::class);

    $t1 = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $produit, 'montant' => 100.00]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance avec reliquat à reporter',
    );
    $source411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );

    $reglements->regler(new PosteTiersReglementData(
        ligneId: (int) $source411->id,
        montantCentimes: 3000,
        date: CarbonImmutable::parse('2026-08-25'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $compteBancaire->id,
        exercice: 2025,
    ));

    $previewNPlus1 = app(ANouveauPreviewBuilder::class)->build(2025);
    $poste411NPlus1 = collect($previewNPlus1->lignes)
        ->first(fn (array $ligne): bool => $ligne['numero_pcg'] === '411');
    $generation = app(ANouveauService::class)->persister(
        $previewNPlus1,
        OrigineANouveau::Cloture,
        $this->acteurReport,
    );
    $ligneAN = $generation->origines()
        ->with('ligneAN.compte')
        ->where('ligne_racine_id', (int) $source411->id)
        ->firstOrFail()
        ->ligneAN;

    $t2 = $reglements->regler(new PosteTiersReglementData(
        ligneId: (int) $ligneAN->id,
        montantCentimes: 7000,
        date: CarbonImmutable::parse('2026-09-05'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $compteBancaire->id,
        exercice: 2026,
    ));
    $ligne411T2 = $t2->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $previewNPlus2 = app(ANouveauPreviewBuilder::class)->build(2026);

    expect($poste411NPlus1)->not->toBeNull()
        ->and($poste411NPlus1['debit'])->toBe('70.00')
        ->and($source411->fresh()->lettrage_code)->toBeNull()
        ->and($ligneAN->fresh()->lettrage_code)->not->toBeNull()
        ->and($ligneAN->fresh()->lettrage_code)->toBe($ligne411T2->fresh()->lettrage_code)
        ->and(collect($previewNPlus2->lignes)->contains(
            fn (array $ligne): bool => $ligne['numero_pcg'] === '411'
                && $ligne['tiers_id'] === (int) $tiers->id
        ))->toBeFalse();
});
