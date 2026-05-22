<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function compteSystemeRF(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

function compte607RF(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg'     => '607rf'.$suffix,
        'intitule'       => 'Achats RF '.$suffix,
        'classe'         => 6,
        'lettrable'      => false,
        'actif'          => true,
        'est_systeme'    => false,
        'pour_inscriptions' => false,
    ]);
}

function compte512RF(string $suffix = 'BNP'): Compte
{
    return Compte::firstOrCreate(
        [
            'association_id' => TenantContext::currentId(),
            'numero_pcg'     => '512'.$suffix,
        ],
        [
            'intitule'          => 'Banque RF '.$suffix,
            'classe'            => 5,
            'lettrable'         => false,
            'actif'             => true,
            'est_systeme'       => false,
            'pour_inscriptions' => false,
        ]
    );
}

/**
 * Crée une dette fournisseur T1 via pourDepenseACredit (nouvelle signature multi-ventilation).
 */
function creerDetteFournisseur(float $montant = 200.00, string $suffix = ''): Transaction
{
    $generator = app(EcritureGenerator::class);
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
    $compteCharge = compte607RF($suffix ?: uniqid());

    return $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => $montant]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Facture RF '.$suffix,
    );
}

// ---------------------------------------------------------------------------
// beforeEach : seed comptes système + 530 conditionnel
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();

    $tenantId = (int) TenantContext::currentId();
    $isSqlite = DB::getDriverName() === 'sqlite';
    $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

    DB::statement(<<<SQL
        {$insertClause} INTO comptes
            (association_id, numero_pcg, intitule, classe, actif, est_systeme, pour_inscriptions, lettrable, created_at, updated_at)
        VALUES
            ({$tenantId}, '530', 'Caisse (espèces)', 5, 1, 1, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    SQL);
});

// ---------------------------------------------------------------------------
// Cas 7 : Règlement chèque émis → T2 : 401 D (tiers) / 512 C (sans tiers) + auto-lettrage
// École 411 systématique : la ligne 5xx C ne porte PLUS de tiers
// ---------------------------------------------------------------------------
test('pourReglementFournisseur chèque crée T2 401 D (tiers) / 512 C (sans tiers) + auto-lettrage', function () {
    $t1 = creerDetteFournisseur(200.00, 'A');
    $compte401 = compteSystemeRF('401');
    $compteBnp = compte512RF('BNPA');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    $tiers = Tiers::find($ligne401T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        libelle: 'Règlement chèque fournisseur',
    );

    expect($t2)->toBeInstanceOf(Transaction::class);
    expect($t2->lignes)->toHaveCount(2);

    // Ligne 401 D : avec tiers
    $ligne401T2 = $t2->lignes->firstWhere('compte_id', $compte401->id);
    expect($ligne401T2)->not->toBeNull('Ligne 401 D attendue dans T2');
    expect((float) $ligne401T2->debit)->toBe(200.00);
    expect((float) $ligne401T2->credit)->toBe(0.00);
    expect((int) $ligne401T2->tiers_id)->toBe((int) $tiers->id);

    // Ligne 512 C : SANS tiers (FEC-conformité)
    $ligne512T2 = $t2->lignes->firstWhere('compte_id', $compteBnp->id);
    expect($ligne512T2)->not->toBeNull('Ligne 512 C attendue dans T2 pour chèque émis');
    expect((float) $ligne512T2->debit)->toBe(0.00);
    expect((float) $ligne512T2->credit)->toBe(200.00);
    expect($ligne512T2->tiers_id)->toBeNull('Ligne 512 C ne porte pas de tiers — FEC-conformité');

    // Auto-lettrage : 401 T1 et 401 T2 partagent le même code
    $ligne401T1Rechargee = $ligne401T1->fresh();
    $ligne401T2Rechargee = $ligne401T2->fresh();

    expect($ligne401T1Rechargee->lettrage_code)->not->toBeNull('401 T1 doit être lettrée');
    expect($ligne401T2Rechargee->lettrage_code)->not->toBeNull('401 T2 doit être lettrée');
    expect($ligne401T1Rechargee->lettrage_code)->toBe($ligne401T2Rechargee->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 8 : Règlement espèces → T2 : 401 D (tiers) / 530 C (sans tiers) + auto-lettrage
// ---------------------------------------------------------------------------
test('pourReglementFournisseur espèces crée T2 401 D (tiers) / 530 C (sans tiers) + auto-lettrage', function () {
    $t1 = creerDetteFournisseur(80.00, 'B');
    $compte401 = compteSystemeRF('401');
    $compte530 = compteSystemeRF('530');
    $compteBnp = compte512RF('BNPB');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    $tiers = Tiers::find($ligne401T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Especes,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    $ligne530T2 = $t2->lignes->firstWhere('compte_id', $compte530->id);
    expect($ligne530T2)->not->toBeNull('Ligne 530 C attendue dans T2 pour espèces');
    expect((float) $ligne530T2->credit)->toBe(80.00);
    expect($ligne530T2->tiers_id)->toBeNull('Ligne 530 C ne porte pas de tiers — FEC-conformité');

    $ligne401T2 = $t2->lignes->firstWhere('compte_id', $compte401->id);
    expect((float) $ligne401T2->debit)->toBe(80.00);
    expect((int) $ligne401T2->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage
    expect($ligne401T1->fresh()->lettrage_code)->toBe($ligne401T2->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 9 : Règlement virement → T2 : 401 D (tiers) / 512BNP C (sans tiers) + auto-lettrage
// ---------------------------------------------------------------------------
test('pourReglementFournisseur virement crée T2 401 D (tiers) / 512BNP C (sans tiers) + auto-lettrage', function () {
    $t1 = creerDetteFournisseur(300.00, 'C');
    $compte401 = compteSystemeRF('401');
    $compteBnp = compte512RF('BNPC');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    $tiers = Tiers::find($ligne401T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-26'),
    );

    $lignePortageT2 = $t2->lignes->firstWhere('compte_id', $compteBnp->id);
    expect($lignePortageT2)->not->toBeNull('Ligne 512BNP C attendue dans T2 pour virement');
    expect((float) $lignePortageT2->credit)->toBe(300.00);
    expect($lignePortageT2->tiers_id)->toBeNull('Ligne 512X ne porte pas de tiers — FEC-conformité');

    $ligne401T2 = $t2->lignes->firstWhere('compte_id', $compte401->id);
    expect((float) $ligne401T2->debit)->toBe(300.00);
    expect((int) $ligne401T2->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage
    expect($ligne401T1->fresh()->lettrage_code)->toBe($ligne401T2->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 10 : Règlement CB → T2 : 401 D (tiers) / 512 C (sans tiers) + auto-lettrage
// ---------------------------------------------------------------------------
test('pourReglementFournisseur CB crée T2 401 D (tiers) / 512 C (sans tiers) + auto-lettrage', function () {
    $t1 = creerDetteFournisseur(150.00, 'D');
    $compte401 = compteSystemeRF('401');
    $compteCB = compte512RF('CBD');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    $tiers = Tiers::find($ligne401T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Cb,
        compteTresorerie: $compteCB,
        datePaiement: new DateTimeImmutable('2026-05-27'),
        libelle: 'Paiement CB fournisseur',
    );

    $lignePortageT2 = $t2->lignes->firstWhere('compte_id', $compteCB->id);
    expect($lignePortageT2)->not->toBeNull();
    expect((float) $lignePortageT2->credit)->toBe(150.00);
    expect($lignePortageT2->tiers_id)->toBeNull();

    $ligne401T2 = $t2->lignes->firstWhere('compte_id', $compte401->id);
    expect((int) $ligne401T2->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage
    expect($ligne401T1->fresh()->lettrage_code)->toBe($ligne401T2->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 11 : Solde ouvert 401 du tiers = 0 après règlement
// ---------------------------------------------------------------------------
test('pourReglementFournisseur : solde ouvert 401 du tiers = 0 après règlement', function () {
    $t1 = creerDetteFournisseur(175.00, 'E');
    $compte401 = compteSystemeRF('401');
    $compteBnp = compte512RF('BNPE');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    $tiers = Tiers::find($ligne401T1->tiers_id);

    $soldeAvant = TransactionLigne::where('compte_id', $compte401->id)
        ->where('tiers_id', $tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeAvant)->toBe(-175.00);

    $generator = app(EcritureGenerator::class);

    $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    $soldeApres = TransactionLigne::where('compte_id', $compte401->id)
        ->where('tiers_id', $tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeApres)->toBe(0.00);
});

// ---------------------------------------------------------------------------
// Cas 12 : Audit lettrage créé
// ---------------------------------------------------------------------------
test('pourReglementFournisseur crée une ligne lettrage_audit action=lettre avec les 2 IDs 401', function () {
    $t1 = creerDetteFournisseur(120.00, 'F');
    $compte401 = compteSystemeRF('401');
    $compteBnp = compte512RF('BNPF');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    $auditBefore = DB::table('lettrage_audit')->where('compte_id', $compte401->id)->count();

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    $auditAfter = DB::table('lettrage_audit')->where('compte_id', $compte401->id)->count();
    expect($auditAfter)->toBe($auditBefore + 1, 'Une ligne audit doit être créée');

    $auditRow = DB::table('lettrage_audit')
        ->where('compte_id', $compte401->id)
        ->where('action', 'lettre')
        ->latest('id')
        ->first();

    expect($auditRow)->not->toBeNull();

    $ligne401T2 = $t2->lignes->firstWhere('compte_id', $compte401->id);
    $idsSnapshot = json_decode($auditRow->transaction_ligne_ids, true);

    expect($idsSnapshot)->toContain((int) $ligne401T1->id);
    expect($idsSnapshot)->toContain((int) $ligne401T2->id);
});

// ---------------------------------------------------------------------------
// Cas 13 : Ligne 401 source déjà lettrée → LettrageDejaPresentException, aucune T2
// ---------------------------------------------------------------------------
test('pourReglementFournisseur lève LettrageDejaPresentException si dette source déjà lettrée', function () {
    $t1 = creerDetteFournisseur(90.00, 'G');
    $compte401 = compteSystemeRF('401');
    $compteBnp = compte512RF('BNPG');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);

    TransactionLigne::where('id', $ligne401T1->id)->update(['lettrage_code' => 'CODE_DEJA_PRESENT_RF']);

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    ))->toThrow(LettrageDejaPresentException::class);

    expect(Transaction::count())->toBe($transactionsBefore, 'Aucune T2 ne doit être créée');
});

// ---------------------------------------------------------------------------
// Cas 14 : T2 équilibrée, type=Depense, mode_paiement=mode passé
// ---------------------------------------------------------------------------
test('pourReglementFournisseur produit T2 equilibree=TRUE, type=Depense, mode_paiement=mode passé', function () {
    $t1 = creerDetteFournisseur(250.00, 'H');
    $compte401 = compteSystemeRF('401');
    $compteBnp = compte512RF('BNPH');

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-28'),
    );

    expect($t2->equilibree)->toBeTrue();
    expect($t2->type_ecriture)->toBe('normale');
    expect($t2->type)->toBe(TypeTransaction::Depense);
    expect($t2->mode_paiement)->toBe(ModePaiement::Virement);

    $totalDebit = $t2->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $t2->lignes->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe(250.00);
    expect($totalCredit)->toBe(250.00);
});

// ---------------------------------------------------------------------------
// Cas 15 : Tiers UNIQUEMENT sur la ligne 401 D de T2 (FEC-conformité)
// ---------------------------------------------------------------------------
test('pourReglementFournisseur : tiers sur 401 D seulement, pas sur 5xx C', function () {
    $t1 = creerDetteFournisseur(130.00, 'I');
    $compte401 = compteSystemeRF('401');
    $compteBnp = compte512RF('BNPI');

    $ligne401T1 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    $tiers = Tiers::find($ligne401T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    // Ligne 401 D : tiers
    $ligne401T2 = $t2->lignes->firstWhere('compte_id', $compte401->id);
    expect((int) $ligne401T2->tiers_id)->toBe((int) $tiers->id);

    // Ligne 5xx C : sans tiers
    $lignePortage = $t2->lignes->firstWhere('compte_id', $compteBnp->id);
    expect($lignePortage->tiers_id)->toBeNull('5xx C ne porte pas de tiers — FEC-conformité');
});

// ---------------------------------------------------------------------------
// Cas 16 : assertPasDeTiersSurClasse5 — vérification globale sur T2
// ---------------------------------------------------------------------------
test('pourReglementFournisseur : aucune ligne classe 5 de T2 ne porte de tiers', function () {
    $t1 = creerDetteFournisseur(60.00, 'J');
    $compteBnp = compte512RF('BNPJ');

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourReglementFournisseur(
        transactionDette: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    foreach ($t2->lignes as $ligne) {
        $compte = Compte::find($ligne->compte_id);
        if ($compte->classe === 5) {
            expect($ligne->tiers_id)->toBeNull(
                "Ligne {$compte->numero_pcg} (classe 5) ne doit pas porter de tiers"
            );
        }
    }
});
