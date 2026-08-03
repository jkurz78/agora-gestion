<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function compteSystemeEnc(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

function compte706Enc(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706enc'.$suffix,
        'intitule' => 'Cotisations encaissement '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function compte512Enc(string $suffix = 'BNP'): Compte
{
    return Compte::firstOrCreate(
        [
            'association_id' => TenantContext::currentId(),
            'numero_pcg' => '512'.$suffix,
        ],
        [
            'intitule' => 'Compte bancaire '.$suffix,
            'classe' => 5,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );
}

function tiersCourantEnc(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

/**
 * Crée une créance T1 via pourRecetteACredit (nouvelle signature multi-ventilation).
 */
function creerCreance(float $montant = 150.00, ?string $suffix = null): Transaction
{
    $generator = app(EcritureGenerator::class);
    $tiers = tiersCourantEnc();
    $compteProduit = compte706Enc($suffix ?? uniqid());

    return $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Facture test '.($suffix ?? uniqid()),
    );
}

// ---------------------------------------------------------------------------
// beforeEach : seed des comptes système + compte 530 manuel si nécessaire
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
// Cas 1 : Encaissement chèque → T2 : 5112 D (sans tiers) / 411 C (avec tiers) + auto-lettrage
// École 411 systématique : la ligne 5xx ne porte PLUS de tiers
// ---------------------------------------------------------------------------
test('pourEncaissementCreance chèque crée T2 5112 D (sans tiers) / 411 C (avec tiers) + auto-lettrage', function () {
    $transactionCreance = creerCreance(150.00, 'CA');

    $compte411 = compteSystemeEnc('411');
    $compte5112 = compteSystemeEnc('5112');
    $compteTresorerie = compte512Enc('BNP');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);
    $tiers = Tiers::find($ligne411T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        libelle: 'Encaissement chèque adhésion',
    );

    expect($t2)->toBeInstanceOf(Transaction::class);
    expect($t2->lignes)->toHaveCount(2);

    // T2 : ligne 5112 D (portage chèque) SANS tiers (FEC-conformité)
    $ligne5112T2 = $t2->lignes->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112T2)->not->toBeNull('La ligne 5112 doit exister dans T2');
    expect((float) $ligne5112T2->debit)->toBe(150.00);
    expect((float) $ligne5112T2->credit)->toBe(0.00);
    expect($ligne5112T2->tiers_id)->toBeNull('Ligne 5112 D ne porte pas de tiers — FEC-conformité');

    // T2 : ligne 411 C avec tiers
    $ligne411T2 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411T2)->not->toBeNull('La ligne 411 doit exister dans T2');
    expect((float) $ligne411T2->debit)->toBe(0.00);
    expect((float) $ligne411T2->credit)->toBe(150.00);
    expect((int) $ligne411T2->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage : 411 de T1 et 411 de T2 partagent le même lettrage_code
    $ligne411T1Rechargee = $ligne411T1->fresh();
    $ligne411T2Rechargee = $ligne411T2->fresh();

    expect($ligne411T1Rechargee->lettrage_code)->not->toBeNull('T1 411 doit être lettrée');
    expect($ligne411T2Rechargee->lettrage_code)->not->toBeNull('T2 411 doit être lettrée');
    expect($ligne411T1Rechargee->lettrage_code)->toBe($ligne411T2Rechargee->lettrage_code, 'T1 et T2 411 doivent partager le même code');
});

// ---------------------------------------------------------------------------
// Cas 2 : Encaissement espèces → T2 : 530 D (sans tiers) / 411 C (avec tiers) + auto-lettrage
// ---------------------------------------------------------------------------
test('pourEncaissementCreance espèces crée T2 530 D (sans tiers) / 411 C (avec tiers) + auto-lettrage', function () {
    $transactionCreance = creerCreance(80.00, 'CB');

    $compte411 = compteSystemeEnc('411');
    $compte530 = compteSystemeEnc('530');
    $compteTresorerie = compte512Enc('BNP2');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);
    $tiers = Tiers::find($ligne411T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Especes,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    $ligne530T2 = $t2->lignes->firstWhere('compte_id', $compte530->id);
    expect($ligne530T2)->not->toBeNull('Ligne 530 attendue dans T2 pour espèces');
    expect((float) $ligne530T2->debit)->toBe(80.00);
    expect($ligne530T2->tiers_id)->toBeNull('Ligne 530 D ne porte pas de tiers — FEC-conformité');

    $ligne411T2 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect((float) $ligne411T2->credit)->toBe(80.00);
    expect((int) $ligne411T2->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage
    $ligne411T1Rechargee = $ligne411T1->fresh();
    expect($ligne411T1Rechargee->lettrage_code)->toBe($ligne411T2->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 3 : Encaissement virement → T2 : 512BNP D (sans tiers) / 411 C (avec tiers) + auto-lettrage
// ---------------------------------------------------------------------------
test('pourEncaissementCreance virement crée T2 512BNP D (sans tiers) / 411 C (avec tiers) + auto-lettrage', function () {
    $transactionCreance = creerCreance(200.00, 'CC');

    $compte411 = compteSystemeEnc('411');
    $compteBnp = compte512Enc('BNP3');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);
    $tiers = Tiers::find($ligne411T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteBnp,
        datePaiement: new DateTimeImmutable('2026-05-26'),
    );

    $lignePortageT2 = $t2->lignes->firstWhere('compte_id', $compteBnp->id);
    expect($lignePortageT2)->not->toBeNull('Ligne 512BNP attendue dans T2 pour virement');
    expect((float) $lignePortageT2->debit)->toBe(200.00);
    expect($lignePortageT2->tiers_id)->toBeNull('Ligne 512X ne porte pas de tiers — FEC-conformité');

    $ligne411T2 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect((float) $ligne411T2->credit)->toBe(200.00);
    expect((int) $ligne411T2->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage
    $ligne411T1Rechargee = $ligne411T1->fresh();
    expect($ligne411T1Rechargee->lettrage_code)->toBe($ligne411T2->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 4 : Encaissement CB → T2 : 512 D (sans tiers) / 411 C (avec tiers) + auto-lettrage
// ---------------------------------------------------------------------------
test('pourEncaissementCreance CB crée T2 512 D (sans tiers) / 411 C (avec tiers) + auto-lettrage', function () {
    $transactionCreance = creerCreance(300.00, 'CD');

    $compte411 = compteSystemeEnc('411');
    $compteCB = compte512Enc('CB1');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);
    $tiers = Tiers::find($ligne411T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Cb,
        compteTresorerie: $compteCB,
        datePaiement: new DateTimeImmutable('2026-05-27'),
        libelle: 'Encaissement CB',
    );

    $lignePortageT2 = $t2->lignes->firstWhere('compte_id', $compteCB->id);
    expect($lignePortageT2)->not->toBeNull('Ligne 512CB1 attendue dans T2 pour CB');
    expect((float) $lignePortageT2->debit)->toBe(300.00);
    expect($lignePortageT2->tiers_id)->toBeNull();

    $ligne411T2 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect((float) $ligne411T2->credit)->toBe(300.00);
    expect((int) $ligne411T2->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage
    expect($ligne411T1->fresh()->lettrage_code)->toBe($ligne411T2->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 5 : Solde ouvert 411 du tiers = 0 après encaissement
// ---------------------------------------------------------------------------
test('pourEncaissementCreance : solde ouvert 411 du tiers = 0 après encaissement', function () {
    $transactionCreance = creerCreance(175.00, 'CE');

    $compte411 = compteSystemeEnc('411');
    $compteTresorerie = compte512Enc('BNP4');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);
    $tiers = Tiers::find($ligne411T1->tiers_id);

    $soldeAvant = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeAvant)->toBe(175.00);

    $generator = app(EcritureGenerator::class);

    $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    $soldeApres = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeApres)->toBe(0.00);
});

// ---------------------------------------------------------------------------
// Cas 6 : Audit lettrage créé
// ---------------------------------------------------------------------------
test('pourEncaissementCreance crée une ligne lettrage_audit action=lettre avec les 2 IDs 411', function () {
    $transactionCreance = creerCreance(120.00, 'CF');

    $compte411 = compteSystemeEnc('411');
    $compteTresorerie = compte512Enc('BNP5');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);

    $auditCountAvant = DB::table('lettrage_audit')->where('compte_id', $compte411->id)->count();

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    $auditCountApres = DB::table('lettrage_audit')->where('compte_id', $compte411->id)->count();
    expect($auditCountApres)->toBe($auditCountAvant + 1, 'Une ligne audit doit être créée');

    $auditRow = DB::table('lettrage_audit')
        ->where('compte_id', $compte411->id)
        ->where('action', 'lettre')
        ->latest('id')
        ->first();

    expect($auditRow)->not->toBeNull();

    $ligne411T2 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    $idsSnapshot = json_decode($auditRow->transaction_ligne_ids, true);

    expect($idsSnapshot)->toContain((int) $ligne411T1->id);
    expect($idsSnapshot)->toContain((int) $ligne411T2->id);
});

// ---------------------------------------------------------------------------
// Cas 7 : Ligne 411 source déjà lettrée → LettrageDejaPresentException, pas de T2
// ---------------------------------------------------------------------------
test('pourEncaissementCreance lève LettrageDejaPresentException si créance déjà lettrée, pas de T2', function () {
    $transactionCreance = creerCreance(90.00, 'CG');

    $compte411 = compteSystemeEnc('411');
    $compteTresorerie = compte512Enc('BNP6');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);

    TransactionLigne::where('id', $ligne411T1->id)->update(['lettrage_code' => 'CODE_DEJA_PRESENT_XXX']);

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    ))->toThrow(LettrageDejaPresentException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 8 : Créance cross-tenant → exception de frontière tenant
// ---------------------------------------------------------------------------
test('pourEncaissementCreance avec créance cross-tenant → TenantBoundaryException ou ModelNotFoundException', function () {
    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    TenantContext::boot($associationB);
    SystemeSeeder::seed();
    $transactionCreanceB = creerCreance(100.00, 'CX');
    TenantContext::boot($associationA);

    $compteTresorerieA = compte512Enc('BNP7');

    $transactionCreanceBBypass = Transaction::withoutGlobalScopes()->find($transactionCreanceB->id);
    $transactionCreanceBBypass->setRelation(
        'lignes',
        TransactionLigne::withoutGlobalScopes()
            ->where('transaction_id', $transactionCreanceBBypass->id)
            ->get()
    );

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    $threw = false;
    try {
        $generator->pourEncaissementCreance(
            transactionCreance: $transactionCreanceBBypass,
            mode: ModePaiement::Cheque,
            compteTresorerie: $compteTresorerieA,
            datePaiement: new DateTimeImmutable('2026-05-25'),
        );
    } catch (TenantBoundaryException $e) {
        $threw = true;
    } catch (ModelNotFoundException $e) {
        $threw = true;
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue('Une exception doit être levée pour une créance cross-tenant');
    expect(Transaction::count())->toBe($transactionsBefore, 'Aucune T2 ne doit être créée');
});

// ---------------------------------------------------------------------------
// Cas 9 : T2 équilibrée, equilibree=TRUE, type_ecriture='normale', mode_paiement=mode passé
// ---------------------------------------------------------------------------
test('pourEncaissementCreance produit T2 equilibree=TRUE, type_ecriture=normale, mode_paiement=mode', function () {
    $transactionCreance = creerCreance(250.00, 'CH');

    $compte411 = compteSystemeEnc('411');
    $compteTresorerie = compte512Enc('BNP8');

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-28'),
    );

    expect($t2->equilibree)->toBeTrue();
    expect($t2->type_ecriture)->toBe('normale');
    expect($t2->type)->toBe(TypeTransaction::Recette);
    expect($t2->mode_paiement)->toBe(ModePaiement::Virement);

    $totalDebit = $t2->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $t2->lignes->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe(250.00);
    expect($totalCredit)->toBe(250.00);
});

// ---------------------------------------------------------------------------
// Cas 10 (révisé) : Tiers UNIQUEMENT sur la ligne 411 C de T2 (FEC-conformité)
// La ligne 5xx D ne porte plus de tiers dans l'école 411 systématique
// ---------------------------------------------------------------------------
test('pourEncaissementCreance : tiers porté sur la ligne 411 C seulement, pas sur 5xx', function () {
    $transactionCreance = creerCreance(130.00, 'CI');

    $compte411 = compteSystemeEnc('411');
    $compte5112 = compteSystemeEnc('5112');
    $compteTresorerie = compte512Enc('BNP9');

    $ligne411T1 = $transactionCreance->lignes->firstWhere('compte_id', $compte411->id);
    $tiers = Tiers::find($ligne411T1->tiers_id);

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-25'),
    );

    // Ligne portage (5112) : tiers_id NULL (FEC-conformité)
    $lignePortage = $t2->lignes->firstWhere('compte_id', $compte5112->id);
    expect($lignePortage->tiers_id)->toBeNull('Le tiers NE DOIT PAS être sur la ligne 5112 (FEC)');

    // Ligne 411 C : tiers_id non null
    $ligne411 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect((int) $ligne411->tiers_id)->toBe((int) $tiers->id, 'Le tiers doit être sur la ligne 411 C');
});

// ---------------------------------------------------------------------------
// Cas 11 : assertPasDeTiersSurClasse5 — vérification globale sur T2
// ---------------------------------------------------------------------------
test('pourEncaissementCreance : aucune ligne classe 5 de T2 ne porte de tiers', function () {
    $transactionCreance = creerCreance(60.00, 'CJ');

    $compteTresorerie = compte512Enc('BNP10');

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $transactionCreance,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTresorerie,
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
