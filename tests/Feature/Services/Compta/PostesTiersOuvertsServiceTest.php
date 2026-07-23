<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\ANouveau\PosteReporteResolver;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

    $this->acteurPostesTiers = User::factory()->create();
    $this->acteurPostesTiers->associations()->attach(TenantContext::currentId(), [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
});

function compteMetierPostesTiers(string $numero, int $classe): Compte
{
    return Compte::create([
        'numero_pcg' => $numero,
        'intitule' => 'Compte '.$numero,
        'classe' => $classe,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

function creerCreancePostesTiers(
    Tiers $tiers,
    float $montant = 100.00,
    string $date = '2026-08-20',
    string $libelle = 'Créance client spéciale',
): Transaction {
    return app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => compteMetierPostesTiers('706-'.str_replace('.', '', (string) $montant), 7),
            'montant' => $montant,
        ]],
        dateConstatation: new DateTimeImmutable($date),
        libelle: $libelle,
    );
}

function creerDettePostesTiers(
    Tiers $tiers,
    float $montant = 45.50,
    string $date = '2026-08-21',
    string $libelle = 'Dette fournisseur spéciale',
): Transaction {
    return app(EcritureGenerator::class)->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => compteMetierPostesTiers('606-'.str_replace('.', '', (string) $montant), 6),
            'montant' => $montant,
        ]],
        dateConstatation: new DateTimeImmutable($date),
        libelle: $libelle,
    );
}

it('projette les creances 411 et les dettes 401 ouvertes en centimes entiers', function (): void {
    $client = Tiers::factory()->create(['nom' => 'Client Projection']);
    $fournisseur = Tiers::factory()->create(['nom' => 'Fournisseur Projection']);
    $creance = creerCreancePostesTiers($client);
    creerDettePostesTiers($fournisseur);

    $service = app(PostesTiersOuvertsService::class);
    $postes = $service->chercher(
        exercice: 2025,
        compte: null,
        tiersId: null,
        exerciceOrigine: null,
        recherche: null,
    );
    $posteCreance = $postes->firstWhere('numeroCompte', '411');

    expect($postes)->toHaveCount(2)
        ->and($posteCreance?->soldeCentimes)->toBe(10000)
        ->and($postes->firstWhere('numeroCompte', '401')?->soldeCentimes)->toBe(4550)
        ->and($service->pourTransaction($creance, 2025)?->ligneActionId)->toBe($posteCreance?->ligneActionId)
        ->and($service->trouver((int) $posteCreance?->ligneActionId, 2025))->toEqual($posteCreance);
});

it('restitue les informations metier de la racine pour un poste reporte', function (): void {
    $client = Tiers::factory()->create(['nom' => 'Client Reporté']);
    $t1 = creerCreancePostesTiers($client);
    $t1->update(['reference' => 'REF-CLIENT-42']);

    app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $this->acteurPostesTiers,
    );

    $poste = app(PostesTiersOuvertsService::class)->chercher(
        exercice: 2026,
        compte: null,
        tiersId: null,
        exerciceOrigine: null,
        recherche: null,
    )->sole();

    expect($poste->estReporte)->toBeTrue()
        ->and($poste->dateAffichage->toDateString())->toBe('2026-09-01')
        ->and($poste->dateOrigine->toDateString())->toBe('2026-08-20')
        ->and($poste->numeroPiece)->toBe($t1->numero_piece)
        ->and($poste->reference)->toBe('REF-CLIENT-42')
        ->and($poste->transactionOrigineId)->toBe((int) $t1->id)
        ->and($poste->exerciceOrigine)->toBe(2025)
        ->and($poste->exerciceActif)->toBe(2026);
});

it('recherche les postes et applique tous les filtres sur la projection metier', function (): void {
    $client = Tiers::factory()->create(['nom' => 'Durand', 'prenom' => 'Alice']);
    $fournisseur = Tiers::factory()->create(['nom' => 'Martin']);
    $creance = creerCreancePostesTiers($client, libelle: 'Cotisation remarquable');
    $creance->update(['reference' => 'LIBRE-ALPHA']);
    creerDettePostesTiers($fournisseur);

    $service = app(PostesTiersOuvertsService::class);
    $numeroPiece = (string) $creance->numero_piece;

    foreach (['Alice Durand', 'LIBRE-ALPHA', $numeroPiece, 'remarquable'] as $recherche) {
        expect($service->chercher(2025, null, null, null, $recherche))
            ->toHaveCount(1)
            ->and($service->chercher(2025, null, null, null, $recherche)->sole()->numeroCompte)
            ->toBe('411');
    }

    expect($service->chercher(2025, '401', null, null, null))->toHaveCount(1)
        ->and($service->chercher(2025, '411', null, null, null))->toHaveCount(1)
        ->and($service->chercher(2025, '512', null, null, null))->toBeEmpty()
        ->and($service->chercher(2025, null, (int) $client->id, null, null))->toHaveCount(1)
        ->and($service->chercher(2025, null, null, 2025, null))->toHaveCount(2)
        ->and($service->chercher(2025, null, null, 2024, null))->toBeEmpty();
});

it('pagine exactement la meme projection triee que chercher', function (): void {
    creerCreancePostesTiers(Tiers::factory()->create(), date: '2026-08-20');
    creerDettePostesTiers(Tiers::factory()->create(), date: '2026-08-21');

    $service = app(PostesTiersOuvertsService::class);
    $postes = $service->chercher(2025, null, null, null, null);
    $page = $service->paginer(2025, null, null, null, null, 1, 2);

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(2)
        ->and($page->currentPage())->toBe(2)
        ->and($page->items())->toHaveCount(1)
        ->and($page->items()[0])->toEqual($postes->values()[1]);
});

it('isole strictement les postes de deux associations', function (): void {
    $associationCourante = TenantContext::current();
    creerCreancePostesTiers(Tiers::factory()->create(), 100.00);

    $associationVoisine = Association::factory()->create();
    TenantContext::boot($associationVoisine);
    SystemeSeeder::seed();
    creerCreancePostesTiers(Tiers::factory()->create(), 999.00);

    TenantContext::boot($associationCourante);
    $postes = app(PostesTiersOuvertsService::class)->chercher(2025, null, null, null, null);

    expect($postes)->toHaveCount(1)
        ->and($postes->sole()->soldeCentimes)->toBe(10000);

    TenantContext::clear();

    expect(app(PostesTiersOuvertsService::class)->chercher(2025, null, null, null, null))
        ->toBeEmpty();
});

it('regroupe les fractions ouvertes sous leur ligne canonique', function (): void {
    $t1 = creerCreancePostesTiers(Tiers::factory()->create());
    $parent = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $parent->update(['lettrage_code' => 'PART-REGLEE']);

    $fractionUne = TransactionLigne::create([
        'transaction_id' => $t1->id,
        'compte_id' => $parent->compte_id,
        'debit' => '30.00',
        'credit' => '0.00',
        'tiers_id' => $parent->tiers_id,
        'poste_tiers_parent_id' => $parent->id,
        'libelle' => $parent->libelle,
        'montant' => '0.00',
    ]);
    $fractionDeux = TransactionLigne::create([
        'transaction_id' => $t1->id,
        'compte_id' => $parent->compte_id,
        'debit' => '20.00',
        'credit' => '0.00',
        'tiers_id' => $parent->tiers_id,
        'poste_tiers_parent_id' => $parent->id,
        'libelle' => $parent->libelle,
        'montant' => '0.00',
    ]);

    $poste = app(PostesTiersOuvertsService::class)->chercher(2025, null, null, null, null)->sole();

    expect($poste->ligneCanoniqueId)->toBe((int) $parent->id)
        ->and($poste->ligneActionId)->toBe((int) $fractionUne->id)
        ->and($poste->ligneIdsOuvertes)->toBe([(int) $fractionUne->id, (int) $fractionDeux->id])
        ->and($poste->soldeCentimes)->toBe(5000)
        ->and(app(PosteReporteResolver::class)->racineId($fractionUne))->toBe((int) $parent->id)
        ->and(app(PosteReporteResolver::class)->depuisLigne(
            $fractionUne,
            CarbonImmutable::parse('2026-08-25'),
        )->is($parent))->toBeTrue();
});

it('recupere de nombreuses fractions sans aggregation tronquable ni requete N plus 1', function (): void {
    $t1 = creerCreancePostesTiers(Tiers::factory()->create());
    $parent = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $parent->update(['lettrage_code' => 'PARENT-FRACTIONNE']);

    TransactionLigne::query()->insert(
        collect(range(1, 300))
            ->map(fn (): array => [
                'transaction_id' => $t1->id,
                'compte_id' => $parent->compte_id,
                'debit' => '0.01',
                'credit' => '0.00',
                'tiers_id' => $parent->tiers_id,
                'poste_tiers_parent_id' => $parent->id,
                'libelle' => $parent->libelle,
                'montant' => '0.00',
            ])
            ->all()
    );
    $idsAttendus = TransactionLigne::query()
        ->where('poste_tiers_parent_id', (int) $parent->id)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $poste = app(PostesTiersOuvertsService::class)->chercher(2025, null, null, null, null)->sole();
    $requêtes = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(function (string $sql): bool {
            $sqlNormalisé = strtolower(str_replace(['"', '`'], '', $sql));

            return str_contains($sqlNormalisé, 'transaction_lignes as ouverte');
        })
        ->values();
    DB::disableQueryLog();
    $sqlNormalisé = strtolower(str_replace(['"', '`'], '', $requêtes->implode(' ')));

    expect($poste->ligneIdsOuvertes)->toBe($idsAttendus)
        ->and($poste->ligneIdsOuvertes)->toHaveCount(300)
        ->and($poste->soldeCentimes)->toBe(300)
        ->and($requêtes)->toHaveCount(2)
        ->and($sqlNormalisé)->not->toContain('group_concat')
        ->and($sqlNormalisé)->not->toContain('join transaction_lignes as parent');
});

it('restitue une fois chaque reglement et detecte un portage remis non annulable', function (): void {
    $t1 = creerCreancePostesTiers(Tiers::factory()->create());
    $banque = compteMetierPostesTiers('512-PT', 5);
    $t2 = app(EcritureGenerator::class)->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $banque,
        datePaiement: new DateTimeImmutable('2026-08-25'),
    );

    $service = app(PostesTiersOuvertsService::class);
    $reglement = $service->reglements($t1)->sole();

    expect($service->soldeActifPourTransaction($t1))->toBe(0)
        ->and($reglement->transactionId)->toBe((int) $t2->id)
        ->and($reglement->montantCentimes)->toBe(10000)
        ->and($reglement->date->toDateString())->toBe('2026-08-25')
        ->and($reglement->mode)->toBe(ModePaiement::Cheque)
        ->and($reglement->annulable)->toBeTrue();

    $t2->lignes()
        ->where('compte_id', compteSysteme('5112')->id)
        ->firstOrFail()
        ->update(['lettrage_code' => 'REMISE-PORTAGE']);

    expect($service->reglements($t1)->sole()->annulable)->toBeFalse();
});

it('apparie les reglements par compte et code de lettrage en cas de collision 401 411', function (): void {
    $generator = app(EcritureGenerator::class);
    $banque = compteMetierPostesTiers('512-COLLISION', 5);
    $creance = creerCreancePostesTiers(Tiers::factory()->create(), 100.00);
    $dette = creerDettePostesTiers(Tiers::factory()->create(), 45.50);
    $t2Creance = $generator->pourEncaissementCreance(
        transactionCreance: $creance,
        mode: ModePaiement::Virement,
        compteTresorerie: $banque,
        datePaiement: new DateTimeImmutable('2026-08-25'),
    );
    $t2Dette = $generator->pourReglementFournisseur(
        transactionDette: $dette,
        mode: ModePaiement::Virement,
        compteTresorerie: $banque,
        datePaiement: new DateTimeImmutable('2026-08-26'),
    );
    $codePartagé = 'COLLISION-401-411';

    foreach ([
        [$creance, compteSysteme('411')],
        [$t2Creance, compteSysteme('411')],
        [$dette, compteSysteme('401')],
        [$t2Dette, compteSysteme('401')],
    ] as [$transaction, $compte]) {
        $transaction->lignes()
            ->where('compte_id', (int) $compte->id)
            ->update(['lettrage_code' => $codePartagé]);
    }

    $service = app(PostesTiersOuvertsService::class);

    expect($service->reglements($creance)->pluck('transactionId')->all())
        ->toBe([(int) $t2Creance->id])
        ->and($service->reglements($dette)->pluck('transactionId')->all())
        ->toBe([(int) $t2Dette->id]);
});
