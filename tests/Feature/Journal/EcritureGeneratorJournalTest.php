<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function compteSystemeJrn(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

function compte706Jrn(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706jrn'.$suffix,
        'intitule' => 'Produits journal '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function compte512Jrn(string $suffix = 'BNP'): Compte
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

function tiersJrn(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

/** Crée une créance T1 via pourRecetteACredit. Retourne la transaction T1. */
function creerCreanceJrn(float $montant = 150.00): Transaction
{
    $generator = app(EcritureGenerator::class);
    $tiers = tiersJrn();
    $compteProduit = compte706Jrn(uniqid());

    return $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Facture test journal',
    );
}

/** Crée un CompteBancaire + le compte 512X correspondant (via BancairesSeeder). */
function creerCompteBancaireJrn(): array
{
    $compteBancaire = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();

    $compte512 = Compte::where('compte_bancaire_id', $compteBancaire->id)
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();

    return [$compteBancaire, $compte512];
}

/** Crée une ligne 5112 source (T1 chèque) via pourRecetteComptant. */
function creerLigne5112SourceJrn(Tiers $tiers, float $montant, Compte $compte512): TransactionLigne
{
    $generator = app(EcritureGenerator::class);
    $compteProduit = compte706Jrn(uniqid());

    $t1 = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compte512,
        date: new DateTimeImmutable('2026-05-20'),
        libelle: 'Recette chèque journal test',
    );

    $compte5112 = compteSystemeJrn('5112');

    return $t1->lignes->firstWhere('compte_id', $compte5112->id);
}

/** Crée une RemiseBancaire pointant vers le CompteBancaire donné. */
function creerRemiseJrn(CompteBancaire $compteBancaire, ModePaiement $mode = ModePaiement::Cheque): RemiseBancaire
{
    $user = User::factory()->create();

    return RemiseBancaire::create([
        'association_id' => TenantContext::currentId(),
        'numero' => rand(1000, 9999),
        'date' => '2026-05-22',
        'mode_paiement' => $mode,
        'compte_cible_id' => $compteBancaire->id,
        'libelle' => 'Remise test journal',
        'saisi_par' => $user->id,
    ]);
}

// ---------------------------------------------------------------------------
// beforeEach : seeds des comptes système
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
// Test 1 : T1 (recette à crédit) → journal=vente ; T2 (encaissement) → journal=banque
// ---------------------------------------------------------------------------

it('T1 (recette à crédit) reçoit journal=vente et T2 (encaissement créance) reçoit journal=banque', function () {
    $t1 = creerCreanceJrn(150.00);

    expect($t1->fresh()->journal)->toBe(JournalComptable::Vente, 'T1 doit avoir journal=vente via hook creating');

    $compte5112 = compteSystemeJrn('5112');
    $compteTresorerie = compte512Jrn('JRNBNP');

    $generator = app(EcritureGenerator::class);

    $t2 = $generator->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTresorerie,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        libelle: 'Encaissement journal test',
    );

    expect($t2->fresh()->journal)->toBe(JournalComptable::Banque, 'T2 (encaissement) doit avoir journal=banque');
});

// ---------------------------------------------------------------------------
// Test 2 : T4 (remise bancaire) → journal=banque
// ---------------------------------------------------------------------------

it('T4 (remise bancaire) reçoit journal=banque', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $remise = creerRemiseJrn($compteBancaire, ModePaiement::Cheque);

    $tiers = tiersJrn();
    $ligne5112 = creerLigne5112SourceJrn($tiers, 80.00, $compte512);

    $generator = app(EcritureGenerator::class);

    $t4 = $generator->pourRemiseBancaire(
        $remise,
        collect([$ligne5112])
    );

    expect($t4->fresh()->journal)->toBe(JournalComptable::Banque, 'T4 (remise bancaire) doit avoir journal=banque');
});
