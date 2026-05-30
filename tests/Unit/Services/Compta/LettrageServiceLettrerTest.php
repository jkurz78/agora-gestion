<?php

declare(strict_types=1);

use App\Exceptions\Compta\CompteNonLettrableException;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Exceptions\Compta\LettrageMultiComptesException;
use App\Exceptions\Compta\LettrageNonEquilibreException;
use App\Exceptions\Compta\LettrageTiersIncoherentException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\LettrageService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Test 1 : lettrage de 2 lignes équilibrées (code fourni) → OK
// ---------------------------------------------------------------------------
test('lettrer deux lignes equilibrees sur compte lettrable avec code fourni → code appliqué et audit créé', function () {
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411',
        'intitule' => 'Clients',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '100.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);
    $code = $service->lettrer(collect([$ligne1, $ligne2]), code: 'MONCODE12345678901X', motif: 'test');

    expect($code)->toBe('MONCODE12345678901X');

    // Vérifie que les lignes ont le code appliqué
    expect(TransactionLigne::find($ligne1->id)->lettrage_code)->toBe('MONCODE12345678901X');
    expect(TransactionLigne::find($ligne2->id)->lettrage_code)->toBe('MONCODE12345678901X');

    // Vérifie que l'audit a été créé avec action='lettre'
    $audit = DB::table('lettrage_audit')
        ->where('lettrage_code', 'MONCODE12345678901X')
        ->where('action', 'lettre')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->compte_id)->toBe($compte->id);
});

// ---------------------------------------------------------------------------
// Test 2 : lettrage sans code → code généré (20 chars)
// ---------------------------------------------------------------------------
test('lettrer deux lignes equilibrees sans code → code généré de 20 caractères', function () {
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '401',
        'intitule' => 'Fournisseurs',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '50.00',
        'credit' => '0.00',
        'montant' => 50,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '50.00',
        'montant' => 50,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);
    $code = $service->lettrer(collect([$ligne1, $ligne2]));

    expect($code)->toBeString()->toHaveLength(20);
    expect(TransactionLigne::find($ligne1->id)->lettrage_code)->toBe($code);
    expect(TransactionLigne::find($ligne2->id)->lettrage_code)->toBe($code);
});

// ---------------------------------------------------------------------------
// Test 3 : compte non lettrable → CompteNonLettrableException, aucune écriture
// ---------------------------------------------------------------------------
test('lettrer sur compte non lettrable → CompteNonLettrableException sans écriture', function () {
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '512BNP',
        'intitule' => 'Compte BNP',
        'classe' => 5,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '75.00',
        'credit' => '0.00',
        'montant' => 75,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '75.00',
        'montant' => 75,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);

    expect(fn () => $service->lettrer(collect([$ligne1, $ligne2])))
        ->toThrow(CompteNonLettrableException::class);

    // Aucune écriture dans audit
    expect(DB::table('lettrage_audit')->count())->toBe(0);
    // Lignes non modifiées
    expect(TransactionLigne::find($ligne1->id)->lettrage_code)->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 4 : lignes sur comptes différents → LettrageMultiComptesException
// ---------------------------------------------------------------------------
test('lettrer des lignes sur comptes différents → LettrageMultiComptesException', function () {
    $compte1 = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411',
        'intitule' => 'Clients',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $compte2 = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '401',
        'intitule' => 'Fournisseurs',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte1->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte2->id,
        'debit' => '0.00',
        'credit' => '100.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);

    expect(fn () => $service->lettrer(collect([$ligne1, $ligne2])))
        ->toThrow(LettrageMultiComptesException::class);
});

// ---------------------------------------------------------------------------
// Test 5 : somme (debit - credit) ≠ 0 → LettrageNonEquilibreException
// ---------------------------------------------------------------------------
test('lettrer des lignes non equilibrees → LettrageNonEquilibreException', function () {
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411',
        'intitule' => 'Clients',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '90.00',  // Pas équilibré : 100 - 90 ≠ 0
        'montant' => 90,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);

    expect(fn () => $service->lettrer(collect([$ligne1, $ligne2])))
        ->toThrow(LettrageNonEquilibreException::class);
});

// ---------------------------------------------------------------------------
// Test 6 : une ligne déjà lettrée → LettrageDejaPresentException
// ---------------------------------------------------------------------------
test('lettrer une ligne déjà lettrée → LettrageDejaPresentException', function () {
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411',
        'intitule' => 'Clients',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'lettrage_code' => 'DEJLETTRE123456789A', // déjà lettrée
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '100.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);

    expect(fn () => $service->lettrer(collect([$ligne1, $ligne2])))
        ->toThrow(LettrageDejaPresentException::class);
});

// ---------------------------------------------------------------------------
// Test 7 : ligne d'un autre tenant → TenantBoundaryException
// ---------------------------------------------------------------------------
test('lettrer une ligne appartenant à un autre tenant → TenantBoundaryException', function () {
    // Tenant A (courant, booté par le global beforeEach)
    $associationA = TenantContext::current();

    $compteA = Compte::create([
        'association_id' => $associationA->id,
        'numero_pcg' => '411',
        'intitule' => 'Clients A',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $txA = Transaction::factory()->create(['association_id' => $associationA->id]);

    $ligneA = TransactionLigne::create([
        'transaction_id' => $txA->id,
        'compte_id' => $compteA->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    // Créer le tenant B et une ligne appartenant à B
    $associationB = Association::factory()->create();
    TenantContext::boot($associationB);

    $compteB = Compte::create([
        'association_id' => $associationB->id,
        'numero_pcg' => '411',
        'intitule' => 'Clients B',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $txB = Transaction::factory()->create(['association_id' => $associationB->id]);

    $ligneB = TransactionLigne::create([
        'transaction_id' => $txB->id,
        'compte_id' => $compteB->id,
        'debit' => '0.00',
        'credit' => '100.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    // Revenir au tenant A
    TenantContext::boot($associationA);

    // Charger ligneB directement (bypass le scope pour le test)
    $ligneBLoaded = TransactionLigne::withoutGlobalScopes()->find($ligneB->id);

    $service = app(LettrageService::class);

    // Lignes de deux tenants différents → TenantBoundaryException
    expect(fn () => $service->lettrer(collect([$ligneA, $ligneBLoaded])))
        ->toThrow(TenantBoundaryException::class);
});

// ---------------------------------------------------------------------------
// Test 8 : audit contient les champs attendus
// ---------------------------------------------------------------------------
test('ligne audit contient transaction_ligne_ids JSON exact, user_id, motif, association_id, compte_id, created_at', function () {
    // Créer un user pour vérifier user_id
    $user = User::factory()->create();
    Auth::login($user);

    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '5112',
        'intitule' => 'Chèques',
        'classe' => 5,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '200.00',
        'credit' => '0.00',
        'montant' => 200,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '200.00',
        'montant' => 200,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);
    $code = $service->lettrer(collect([$ligne1, $ligne2]), motif: 'test audit champs');

    $audit = DB::table('lettrage_audit')
        ->where('lettrage_code', $code)
        ->where('action', 'lettre')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->compte_id)->toBe($compte->id);
    expect($audit->association_id)->toBe(TenantContext::currentId());
    expect($audit->user_id)->toBe($user->id);
    expect($audit->motif)->toBe('test audit champs');
    expect($audit->created_at)->not->toBeNull();

    $ids = json_decode($audit->transaction_ligne_ids, true);
    expect($ids)->toBeArray()->toHaveCount(2);
    expect($ids)->toContain($ligne1->id)->toContain($ligne2->id);

    Auth::logout();
});

// ---------------------------------------------------------------------------
// Test 9 : code fourni en argument respecté
// ---------------------------------------------------------------------------
test('code fourni en argument est respecté — retour et transaction_lignes cohérents', function () {
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411',
        'intitule' => 'Clients',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '300.00',
        'credit' => '0.00',
        'montant' => 300,
        'sous_categorie_id' => null,
    ]);

    $ligne2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '300.00',
        'montant' => 300,
        'sous_categorie_id' => null,
    ]);

    $codeFixe = 'CODEFIXTESTCODE12345'; // exactement 20 chars

    $service = app(LettrageService::class);
    $retour = $service->lettrer(collect([$ligne1, $ligne2]), code: $codeFixe);

    expect($retour)->toBe($codeFixe);
    expect(TransactionLigne::find($ligne1->id)->lettrage_code)->toBe($codeFixe);
    expect(TransactionLigne::find($ligne2->id)->lettrage_code)->toBe($codeFixe);
});

// ---------------------------------------------------------------------------
// Test : lettrage de 2 lignes équilibrées mais de tiers différents → refus
// (invariant « même tiers » — sinon corruption des comptes auxiliaires 411/401)
// ---------------------------------------------------------------------------
test('lettrer deux lignes equilibrees mais de tiers differents → LettrageTiersIncoherentException', function () {
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411',
        'intitule' => 'Clients',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
    ]);

    $tiersA = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
    $tiersB = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);

    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligneA = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'tiers_id' => $tiersA->id,
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    $ligneB = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '100.00',
        'tiers_id' => $tiersB->id,
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);

    expect(fn () => $service->lettrer(collect([$ligneA, $ligneB])))
        ->toThrow(LettrageTiersIncoherentException::class);

    // Aucune des deux lignes ne doit avoir été lettrée (échec avant écriture).
    expect(TransactionLigne::find($ligneA->id)->lettrage_code)->toBeNull();
    expect(TransactionLigne::find($ligneB->id)->lettrage_code)->toBeNull();
});
