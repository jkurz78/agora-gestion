<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\CompteIncorrectException;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Models\Association;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Récupère le compte système par numero_pcg pour le tenant courant.
 */
function compteSystemeRem(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

/**
 * Crée un compte produit classe 7 pour le tenant courant (unique par suffix).
 */
function compte706Rem(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706rem'.$suffix,
        'intitule' => 'Cotisations remise '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

/**
 * Crée un tiers pour le tenant courant.
 */
function tiersRem(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

/**
 * Crée un CompteBancaire + le compte 512X correspondant (via BancairesSeeder).
 * Retourne le Compte 512X.
 */
function creerCompteBancaireAvec512(): array
{
    $compteBancaire = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    // Rejoue le seed pour créer le compte 512X correspondant
    BancairesSeeder::seed();

    // Résolution : le Compte 512X correspond à ce CompteBancaire par compte_bancaire_id
    $compte512 = Compte::where('compte_bancaire_id', $compteBancaire->id)
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();

    return [$compteBancaire, $compte512];
}

/**
 * Crée une ligne 5112 source (T1 chèque) via pourRecetteComptant (signature multi-ventilation).
 * Retourne la ligne 5112 débit de T1 (sans tiers — école 411 systématique).
 */
function creerLigne5112Source(Tiers $tiers, float $montant, Compte $compteBancaire512): TransactionLigne
{
    $generator = app(EcritureGenerator::class);
    $compteProduit = compte706Rem(uniqid());

    $t1 = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteBancaire512,
        date: new DateTimeImmutable('2026-05-20'),
        libelle: 'Recette chèque test',
    );

    $compte5112 = compteSystemeRem('5112');

    return $t1->lignes->firstWhere('compte_id', $compte5112->id);
}

/**
 * Crée une ligne 530 source (T1 espèces) via pourRecetteComptant (signature multi-ventilation).
 * Retourne la ligne 530 débit de T1 (sans tiers — école 411 systématique).
 */
function creerLigne530Source(Tiers $tiers, float $montant, Compte $compteBancaire512): TransactionLigne
{
    $generator = app(EcritureGenerator::class);
    $compteProduit = compte706Rem(uniqid());

    $t1 = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        mode: ModePaiement::Especes,
        compteTresorerie: $compteBancaire512,
        date: new DateTimeImmutable('2026-05-20'),
        libelle: 'Recette espèces test',
    );

    $compte530 = compteSystemeRem('530');

    return $t1->lignes->firstWhere('compte_id', $compte530->id);
}

/**
 * Crée une RemiseBancaire pointant vers le CompteBancaire donné.
 */
function creerRemise(CompteBancaire $compteBancaire, ModePaiement $mode = ModePaiement::Cheque): RemiseBancaire
{
    // saisi_par est FK NOT NULL → crée un user de test (User n'est pas tenant-scopé)
    $user = User::factory()->create();

    return RemiseBancaire::create([
        'association_id' => TenantContext::currentId(),
        'numero' => rand(1000, 9999),
        'date' => '2026-05-22',
        'mode_paiement' => $mode,
        'compte_cible_id' => $compteBancaire->id,
        'libelle' => 'Remise test',
        'saisi_par' => $user->id,
    ]);
}

// ---------------------------------------------------------------------------
// beforeEach : seeds des comptes système (411, 401, 5112) + 530 manuel
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();

    // 530 (Caisse) est conditionnel dans SystemeSeeder → insérer directement pour tests espèces
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
// Cas 1 : Scénario plan — remise 3 chèques (Pierre 50, Paul 30, Jeanne 20) sur 512BNP
// ---------------------------------------------------------------------------
test('pourRemiseBancaire crée T4 avec 4 lignes (1 D 512 + 3 C 5112 sans tiers) + auto-lettrage 1↔1', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $pierre = tiersRem();
    $paul = tiersRem();
    $jeanne = tiersRem();

    $compte5112 = compteSystemeRem('5112');

    $lignePierre = creerLigne5112Source($pierre, 50.00, $compte512);
    $lignePaul = creerLigne5112Source($paul, 30.00, $compte512);
    $ligneJeanne = creerLigne5112Source($jeanne, 20.00, $compte512);

    $generator = app(EcritureGenerator::class);

    $t4 = $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre, $lignePaul, $ligneJeanne])
    );

    expect($t4)->toBeInstanceOf(Transaction::class);
    expect($t4->lignes)->toHaveCount(4);

    // Ligne 512 D 100 sans tiers, non lettrée (rappro bancaire à venir)
    $ligne512 = $t4->lignes->firstWhere('compte_id', $compte512->id);
    expect($ligne512)->not->toBeNull('Ligne 512 D attendue');
    expect((float) $ligne512->debit)->toBe(100.00);
    expect((float) $ligne512->credit)->toBe(0.00);
    expect($ligne512->tiers_id)->toBeNull('Ligne 512 ne doit pas porter de tiers');
    expect($ligne512->lettrage_code)->toBeNull('Ligne 512 ne doit pas être lettrée');

    // 3 lignes 5112 C (une par ligne source, 1↔1) — école 411 systématique : pas de tiers sur 5112
    $lignes5112T4 = $t4->lignes->where('compte_id', $compte5112->id);
    expect($lignes5112T4)->toHaveCount(3);

    foreach ($lignes5112T4 as $ligne) {
        expect($ligne->tiers_id)->toBeNull('Ligne 5112 C ne doit pas porter de tiers (école 411 systématique)');
        expect($ligne->lettrage_code)->not->toBeNull('Ligne 5112 C doit être lettrée');
    }

    // Les 3 lignes T4 ont 3 codes distincts
    $codes = $lignes5112T4->pluck('lettrage_code')->unique();
    expect($codes->count())->toBe(3, '3 codes lettrage distincts (1 par paire)');

    // Chaque ligne source partage son code avec exactement une ligne T4 de même montant
    $lignePierre->refresh();
    $lignePaul->refresh();
    $ligneJeanne->refresh();

    foreach ([[$lignePierre, 50.00], [$lignePaul, 30.00], [$ligneJeanne, 20.00]] as [$source, $montant]) {
        expect($source->lettrage_code)->not->toBeNull("Ligne source #{$source->id} doit être lettrée");
        $ligneT4 = $lignes5112T4->firstWhere('lettrage_code', $source->lettrage_code);
        expect($ligneT4)->not->toBeNull("Code {$source->lettrage_code} attendu sur une ligne T4");
        expect((float) $ligneT4->credit)->toBe($montant, "Ligne T4 lettrée à #{$source->id} doit avoir le même montant");
    }
});

// ---------------------------------------------------------------------------
// Cas 2 : Plusieurs chèques du même tiers → 1 ligne T4 par source (école 411), codes distincts
// ---------------------------------------------------------------------------
test('pourRemiseBancaire : plusieurs chèques même tiers → 1 ligne T4 par chèque, codes distincts (école 411 systématique)', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $pierre = tiersRem();
    $paul = tiersRem();

    $compte5112 = compteSystemeRem('5112');

    $lignePierre1 = creerLigne5112Source($pierre, 50.00, $compte512);
    $lignePierre2 = creerLigne5112Source($pierre, 30.00, $compte512);
    $lignePaul = creerLigne5112Source($paul, 20.00, $compte512);

    $generator = app(EcritureGenerator::class);

    $t4 = $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre1, $lignePierre2, $lignePaul])
    );

    // T4 doit avoir 4 lignes : 1 D 512 + 3 C 5112 (1 par chèque source, pas de groupement)
    expect($t4->lignes)->toHaveCount(4);

    $lignes5112T4 = $t4->lignes->where('compte_id', $compte5112->id);
    expect($lignes5112T4)->toHaveCount(3, '1 ligne 5112 par ligne source (pas de groupement par tiers)');

    // Aucune ligne 5112 ne porte de tiers
    foreach ($lignes5112T4 as $ligne) {
        expect($ligne->tiers_id)->toBeNull('Ligne 5112 C ne doit pas porter de tiers');
    }

    // Les 3 lignes T4 ont 3 codes distincts (même les 2 chèques de Pierre)
    $codes = $lignes5112T4->pluck('lettrage_code')->unique();
    expect($codes->count())->toBe(3, '3 codes distincts pour 3 sources (école 411 systématique)');

    // Chaque source partage son code avec exactement une ligne T4 de même montant
    $lignePierre1->refresh();
    $lignePierre2->refresh();
    $lignePaul->refresh();

    expect($lignePierre1->lettrage_code)->not->toBeNull();
    expect($lignePierre2->lettrage_code)->not->toBeNull();
    expect($lignePaul->lettrage_code)->not->toBeNull();

    // Les 3 sources ont 3 codes distincts (1↔1 strict)
    expect($lignePierre1->lettrage_code)->not->toBe($lignePierre2->lettrage_code);
    expect($lignePierre1->lettrage_code)->not->toBe($lignePaul->lettrage_code);
    expect($lignePierre2->lettrage_code)->not->toBe($lignePaul->lettrage_code);
});

// ---------------------------------------------------------------------------
// Cas 3 : Solde ouvert 5112 = 0 après remise (école 411 systématique : pas de tiers sur 5112)
// ---------------------------------------------------------------------------
test('pourRemiseBancaire : solde ouvert 5112 = 0 après remise (lettrage par paire 1↔1)', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $pierre = tiersRem();
    $paul = tiersRem();

    $compte5112 = compteSystemeRem('5112');

    $lignePierre = creerLigne5112Source($pierre, 75.00, $compte512);
    $lignePaul = creerLigne5112Source($paul, 25.00, $compte512);

    // Avant remise : solde 5112 global = 100 (les 2 chèques en attente)
    $soldeAvant = TransactionLigne::where('compte_id', $compte5112->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeAvant)->toBe(100.00);

    $generator = app(EcritureGenerator::class);

    $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre, $lignePaul])
    );

    // Après remise : solde 5112 global = 0 (toutes les paires lettrées)
    $soldeApres = TransactionLigne::where('compte_id', $compte5112->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeApres)->toBe(0.00);

    // Solde ouvert 5112 (lignes non lettrées seulement) = 0
    $soldeOuvert = TransactionLigne::where('compte_id', $compte5112->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeOuvert)->toBe(0.00);
});

// ---------------------------------------------------------------------------
// Cas 4 : Audit lettrage — autant de lignes action='lettre' que de tiers groupés
// ---------------------------------------------------------------------------
test('pourRemiseBancaire crée 3 lignes lettrage_audit action=lettre pour 3 tiers distincts', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $pierre = tiersRem();
    $paul = tiersRem();
    $jeanne = tiersRem();

    $compte5112 = compteSystemeRem('5112');

    $lignePierre = creerLigne5112Source($pierre, 50.00, $compte512);
    $lignePaul = creerLigne5112Source($paul, 30.00, $compte512);
    $ligneJeanne = creerLigne5112Source($jeanne, 20.00, $compte512);

    $auditAvant = DB::table('lettrage_audit')
        ->where('compte_id', $compte5112->id)
        ->where('action', 'lettre')
        ->count();

    $generator = app(EcritureGenerator::class);

    $t4 = $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre, $lignePaul, $ligneJeanne])
    );

    $auditApres = DB::table('lettrage_audit')
        ->where('compte_id', $compte5112->id)
        ->where('action', 'lettre')
        ->count();

    expect($auditApres)->toBe($auditAvant + 3, '3 lignes audit créées (1 par tiers groupé)');

    // Chaque audit doit contenir au moins 2 IDs (ligne source + ligne T4)
    $audits = DB::table('lettrage_audit')
        ->where('compte_id', $compte5112->id)
        ->where('action', 'lettre')
        ->orderBy('id', 'desc')
        ->limit(3)
        ->get();

    foreach ($audits as $audit) {
        $ids = json_decode($audit->transaction_ligne_ids, true);
        expect(count($ids))->toBeGreaterThanOrEqual(2, 'Chaque audit doit contenir au moins 2 IDs de lignes');
    }
});

// ---------------------------------------------------------------------------
// Cas 5 : T4 équilibrée, equilibree=TRUE, type_ecriture='normale', mode_paiement
// ---------------------------------------------------------------------------
test('pourRemiseBancaire produit T4 equilibree=TRUE, type_ecriture=normale, mode_paiement=remise.mode_paiement, montant_total=total', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $pierre = tiersRem();
    $paul = tiersRem();

    $lignePierre = creerLigne5112Source($pierre, 60.00, $compte512);
    $lignePaul = creerLigne5112Source($paul, 40.00, $compte512);

    $generator = app(EcritureGenerator::class);

    $t4 = $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre, $lignePaul])
    );

    expect($t4->equilibree)->toBeTrue();
    expect($t4->type_ecriture)->toBe('normale');
    expect($t4->type)->toBe(TypeTransaction::Recette);
    expect($t4->mode_paiement)->toBe(ModePaiement::Cheque);
    expect((float) $t4->montant_total)->toBe(100.00);

    // T4 équilibrée : ∑D = ∑C
    $totalDebit = $t4->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $t4->lignes->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe(100.00);
    expect($totalCredit)->toBe(100.00);
});

// ---------------------------------------------------------------------------
// Cas 6 : Remise espèces — portage 530, compte cible 512X
// ---------------------------------------------------------------------------
test('pourRemiseBancaire espèces : portage 530, cible 512X, lignes 530 C sans tiers', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Especes);

    $pierre = tiersRem();
    $paul = tiersRem();

    $compte530 = compteSystemeRem('530');

    $lignePierre = creerLigne530Source($pierre, 40.00, $compte512);
    $lignePaul = creerLigne530Source($paul, 60.00, $compte512);

    $generator = app(EcritureGenerator::class);

    $t4 = $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre, $lignePaul])
    );

    expect($t4)->toBeInstanceOf(Transaction::class);
    // 3 lignes : 1 D 512 + 2 C 530 (1 par source)
    expect($t4->lignes)->toHaveCount(3);

    // Ligne 512 D 100, sans tiers
    $ligne512 = $t4->lignes->firstWhere('compte_id', $compte512->id);
    expect((float) $ligne512->debit)->toBe(100.00);
    expect($ligne512->tiers_id)->toBeNull();

    // Lignes 530 C (une par ligne source) sans tiers (école 411 systématique)
    $lignes530T4 = $t4->lignes->where('compte_id', $compte530->id);
    expect($lignes530T4)->toHaveCount(2);

    foreach ($lignes530T4 as $ligne) {
        expect($ligne->tiers_id)->toBeNull('Ligne 530 C ne doit pas porter de tiers (école 411 systématique)');
        expect($ligne->lettrage_code)->not->toBeNull('Ligne 530 C doit être lettrée');
    }

    $montants = $lignes530T4->pluck('credit')->map(fn ($c) => (float) $c)->sort()->values()->all();
    expect($montants)->toBe([40.00, 60.00], 'Montants des 2 lignes 530 C correspondent aux sources');

    expect($t4->mode_paiement)->toBe(ModePaiement::Especes);
});

// ---------------------------------------------------------------------------
// Cas 7 : Mode invalide (Virement, Cb) → \InvalidArgumentException
// ---------------------------------------------------------------------------
test('pourRemiseBancaire lève InvalidArgumentException pour un mode non supporté (Virement)', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Virement);

    $pierre = tiersRem();

    // Pour ce test on crée une ligne 512 (peu importe le compte — l'exception doit lever avant)
    // On passe un objet Collection minimal pour déclencher la validation de mode
    $compte5112 = compteSystemeRem('5112');
    $ligneFake = TransactionLigne::create([
        'transaction_id' => Transaction::create([
            'association_id' => TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'date' => '2026-05-20',
            'libelle' => 'Fake',
            'montant_total' => 10,
            'equilibree' => false,
            'type_ecriture' => 'normale',
        ])->id,
        'compte_id' => $compte5112->id,
        'debit' => 10,
        'credit' => 0,
        'tiers_id' => $pierre->id,
        'libelle' => 'Fake',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRemiseBancaire(
        $remise,
        collect([$ligneFake])
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 8 : Lignes sources vides → \InvalidArgumentException
// ---------------------------------------------------------------------------
test('pourRemiseBancaire lève InvalidArgumentException si lignes sources vides', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRemiseBancaire(
        $remise,
        collect([])
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 9 supprimé 2026-05-22 : en école 411 systématique, les lignes 5112 sources
// n'ont plus de tiers — la validation « tiers obligatoire sur source » a disparu.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Cas 10 : Lignes sources sur comptes différents → \InvalidArgumentException
// ---------------------------------------------------------------------------
test('pourRemiseBancaire lève InvalidArgumentException si lignes sources sur comptes différents', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $pierre = tiersRem();
    $paul = tiersRem();

    $compte5112 = compteSystemeRem('5112');
    $compte530 = compteSystemeRem('530');

    // Ligne sur 5112
    $lignePierre5112 = TransactionLigne::create([
        'transaction_id' => Transaction::create([
            'association_id' => TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'date' => '2026-05-20',
            'libelle' => 'Mix5112',
            'montant_total' => 50,
            'equilibree' => false,
            'type_ecriture' => 'normale',
        ])->id,
        'compte_id' => $compte5112->id,
        'debit' => 50,
        'credit' => 0,
        'tiers_id' => $pierre->id,
        'libelle' => 'Mix5112',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    // Ligne sur 530 (compte différent)
    $lignePaul530 = TransactionLigne::create([
        'transaction_id' => Transaction::create([
            'association_id' => TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'date' => '2026-05-20',
            'libelle' => 'Mix530',
            'montant_total' => 30,
            'equilibree' => false,
            'type_ecriture' => 'normale',
        ])->id,
        'compte_id' => $compte530->id,
        'debit' => 30,
        'credit' => 0,
        'tiers_id' => $paul->id,
        'libelle' => 'Mix530',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre5112, $lignePaul530])
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 11 : Compte cible non bancaire (ex : 530 ou 5112) → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourRemiseBancaire leve CompteIncorrectException si compte cible nest pas 512X', function () {
    // La résolution du compte cible se fait par compte_bancaire_id. Pour provoquer
    // l'erreur : on rattache un Compte NON bancaire physique (ici un 530, classe 5
    // mais numero_pcg != 512X) à un CompteBancaire, puis on pointe la remise dessus.
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();

    $compteBancaireFake = CompteBancaire::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Caisse fake',
        'iban' => 'FAKE-IBAN-530-'.uniqid(),
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
    ]);

    // Le compte 530 (caisse) n'est pas un 512X bancaire physique : on le rattache
    // au CompteBancaire fake pour que la résolution le retourne et échoue.
    $compte530 = compteSystemeRem('530');
    $compte530->update(['compte_bancaire_id' => $compteBancaireFake->id]);

    $remiseFake = creerRemise($compteBancaireFake, ModePaiement::Cheque);

    $pierre = tiersRem();
    $lignePierre = creerLigne5112Source($pierre, 50.00, $compte512);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRemiseBancaire(
        $remiseFake,
        collect([$lignePierre])
    ))->toThrow(CompteIncorrectException::class);
});

// ---------------------------------------------------------------------------
// Cas 12 : Lignes sources cross-tenant → TenantBoundaryException ou ModelNotFoundException
// ---------------------------------------------------------------------------
test('pourRemiseBancaire avec lignes cross-tenant → exception de frontière tenant', function () {
    [$compteBancaireA, $compte512A] = creerCompteBancaireAvec512();
    $remiseA = creerRemise($compteBancaireA, ModePaiement::Cheque);

    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    // Créer une ligne 5112 dans le tenant B
    TenantContext::boot($associationB);
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

    $pierreB = tiersRem();
    [$compteBancaireB, $compte512B] = creerCompteBancaireAvec512();
    $ligneB = creerLigne5112Source($pierreB, 50.00, $compte512B);

    TenantContext::boot($associationA); // Revenir au tenant A

    $generator = app(EcritureGenerator::class);

    // La ligne B a un compte_id du tenant B → doit lever une exception de frontière
    $threw = false;
    try {
        $generator->pourRemiseBancaire(
            $remiseA,
            collect([$ligneB])
        );
    } catch (TenantBoundaryException $e) {
        $threw = true;
    } catch (ModelNotFoundException $e) {
        $threw = true;
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue('Une exception doit être levée pour des lignes cross-tenant');
});

// ---------------------------------------------------------------------------
// Cas 13 : Une ligne source déjà lettrée → LettrageDejaPresentException + rollback
// ---------------------------------------------------------------------------
test('pourRemiseBancaire lève LettrageDejaPresentException si une ligne source est déjà lettrée, pas de T4', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireAvec512();
    $remise = creerRemise($compteBancaire, ModePaiement::Cheque);

    $pierre = tiersRem();
    $paul = tiersRem();

    $lignePierre = creerLigne5112Source($pierre, 50.00, $compte512);
    $lignePaul = creerLigne5112Source($paul, 30.00, $compte512);

    // Lettrage manuel de la ligne Pierre (simuler une double-remise)
    TransactionLigne::where('id', $lignePierre->id)->update(['lettrage_code' => 'DEJA_LETTRE_CODE']);

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRemiseBancaire(
        $remise,
        collect([$lignePierre, $lignePaul])
    ))->toThrow(LettrageDejaPresentException::class);

    // Aucune T4 créée (rollback)
    expect(Transaction::count())->toBe($transactionsBefore, 'Aucune T4 ne doit être créée sur erreur de lettrage');
});
