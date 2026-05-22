<?php

declare(strict_types=1);

use App\Exceptions\Compta\EcritureNonEquilibreeException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Exceptions\Compta\TiersInterditException;
use App\Exceptions\Compta\TiersRequisException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Test 1 : EcritureGenerator est résoluble via le container (injection OK)
// ---------------------------------------------------------------------------
test('EcritureGenerator est résoluble via le container et son LettrageService est injecté', function () {
    $generator = app(EcritureGenerator::class);

    expect($generator)->toBeInstanceOf(EcritureGenerator::class);
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée une TransactionLigne partielle (sans persistance DB) avec les champs
 * nécessaires aux invariants du squelette.
 *
 * @param  array<string, mixed>  $attrs
 */
function makeLigneStub(array $attrs = []): TransactionLigne
{
    $ligne = new TransactionLigne;
    foreach ($attrs as $key => $value) {
        $ligne->$key = $value;
    }

    return $ligne;
}

// ---------------------------------------------------------------------------
// Tests 2-4 : assertEquilibre
// ---------------------------------------------------------------------------
test('assertEquilibre accepte 2 lignes équilibrées debit==credit', function () {
    $generator = app(EcritureGenerator::class);

    $ligne1 = makeLigneStub(['debit' => '100.00', 'credit' => '0.00']);
    $ligne2 = makeLigneStub(['debit' => '0.00', 'credit' => '100.00']);

    // Doit passer sans exception
    $generator->assertEquilibre(collect([$ligne1, $ligne2]));

    expect(true)->toBeTrue(); // atteint ici = pas d'exception
});

test('assertEquilibre rejette 2 lignes déséquilibrées → EcritureNonEquilibreeException', function () {
    $generator = app(EcritureGenerator::class);

    $ligne1 = makeLigneStub(['debit' => '100.00', 'credit' => '0.00']);
    $ligne2 = makeLigneStub(['debit' => '0.00', 'credit' => '90.00']); // 10 € de déséquilibre

    expect(fn () => $generator->assertEquilibre(collect([$ligne1, $ligne2])))
        ->toThrow(EcritureNonEquilibreeException::class);
});

test('assertEquilibre accepte 4 lignes équilibrées en somme même si non appairées individuellement', function () {
    $generator = app(EcritureGenerator::class);

    // ∑D = 250 + 75 = 325 ; ∑C = 200 + 125 = 325 → équilibré
    $l1 = makeLigneStub(['debit' => '250.00', 'credit' => '0.00']);
    $l2 = makeLigneStub(['debit' => '75.00', 'credit' => '0.00']);
    $l3 = makeLigneStub(['debit' => '0.00', 'credit' => '200.00']);
    $l4 = makeLigneStub(['debit' => '0.00', 'credit' => '125.00']);

    $generator->assertEquilibre(collect([$l1, $l2, $l3, $l4]));

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Tests 5-6 : assertTenantCoherence
// ---------------------------------------------------------------------------
test("assertTenantCoherence rejette comptes d'un autre tenant → TenantBoundaryException", function () {
    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    // Compte appartenant au tenant B
    TenantContext::boot($associationB);
    $compteB = Compte::create([
        'association_id' => $associationB->id,
        'numero_pcg' => '706',
        'intitule' => 'Cotisations B',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
    TenantContext::boot($associationA); // revenir au tenant A

    // Charger sans scope global (bypass isolation)
    $compteBLoaded = Compte::withoutGlobalScopes()->find($compteB->id);

    $tx = Transaction::factory()->create(['association_id' => $associationA->id]);
    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteBLoaded->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);
    $ligne->setRelation('compte', $compteBLoaded);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->assertTenantCoherence(collect([$ligne])))
        ->toThrow(TenantBoundaryException::class);
});

test("assertTenantCoherence rejette tiers d'un autre tenant → TenantBoundaryException", function () {
    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    // Tiers appartenant au tenant B (bypass scope)
    $tiersB = Tiers::withoutGlobalScopes()
        ->where('association_id', $associationB->id)
        ->first();

    if ($tiersB === null) {
        // Créer un tiers B si inexistant
        TenantContext::boot($associationB);
        $tiersB = Tiers::factory()->create(['association_id' => $associationB->id]);
        TenantContext::boot($associationA);
    } else {
        TenantContext::boot($associationA);
    }

    // Compte du tenant A (courant)
    $compteA = Compte::create([
        'association_id' => $associationA->id,
        'numero_pcg' => '411',
        'intitule' => 'Clients A',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
    ]);

    $tx = Transaction::factory()->create(['association_id' => $associationA->id]);
    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteA->id,
        'tiers_id' => $tiersB->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);
    $ligne->setRelation('compte', $compteA);

    $tiersCollection = collect([$tiersB]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->assertTenantCoherence(collect([$ligne]), $tiersCollection))
        ->toThrow(TenantBoundaryException::class);
});

// ---------------------------------------------------------------------------
// Tests 7-10 : assertTiersObligatoire411
// ---------------------------------------------------------------------------
test('assertTiersObligatoire411 accepte ligne 411 avec tiers_id non null', function () {
    $generator = app(EcritureGenerator::class);

    $compte = new Compte(['numero_pcg' => '411']);
    $ligne = makeLigneStub(['tiers_id' => 42, 'debit' => '100.00', 'credit' => '0.00']);
    $ligne->setRelation('compte', $compte);

    $generator->assertTiersObligatoire411(collect([$ligne]));

    expect(true)->toBeTrue();
});

test('assertTiersObligatoire411 rejette ligne 411 sans tiers_id → TiersRequisException', function () {
    $generator = app(EcritureGenerator::class);

    $compte = new Compte(['numero_pcg' => '411']);
    $ligne = makeLigneStub(['tiers_id' => null, 'debit' => '100.00', 'credit' => '0.00']);
    $ligne->setRelation('compte', $compte);

    expect(fn () => $generator->assertTiersObligatoire411(collect([$ligne])))
        ->toThrow(TiersRequisException::class);
});

test('assertTiersObligatoire411 accepte ligne 706 sans tiers_id (pas dans la liste 411/401)', function () {
    $generator = app(EcritureGenerator::class);

    $compte = new Compte(['numero_pcg' => '706']);
    $ligne = makeLigneStub(['tiers_id' => null, 'debit' => '0.00', 'credit' => '100.00']);
    $ligne->setRelation('compte', $compte);

    $generator->assertTiersObligatoire411(collect([$ligne]));

    expect(true)->toBeTrue();
});

test('assertTiersObligatoire411 accepte ligne 401 avec tiers (idem 411)', function () {
    $generator = app(EcritureGenerator::class);

    $compte = new Compte(['numero_pcg' => '401']);
    $ligne = makeLigneStub(['tiers_id' => 7, 'debit' => '0.00', 'credit' => '50.00']);
    $ligne->setRelation('compte', $compte);

    $generator->assertTiersObligatoire411(collect([$ligne]));

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Tests 11-13 : assertPasDeTiersSur512
// ---------------------------------------------------------------------------
test('assertPasDeTiersSur512 rejette ligne 512BNP avec tiers_id non null → TiersInterditException', function () {
    $generator = app(EcritureGenerator::class);

    $compte = new Compte(['numero_pcg' => '5121']);
    $ligne = makeLigneStub(['tiers_id' => 5, 'debit' => '100.00', 'credit' => '0.00']);
    $ligne->setRelation('compte', $compte);

    expect(fn () => $generator->assertPasDeTiersSur512(collect([$ligne])))
        ->toThrow(TiersInterditException::class);
});

test('assertPasDeTiersSur512 accepte ligne 5121 sans tiers', function () {
    $generator = app(EcritureGenerator::class);

    $compte = new Compte(['numero_pcg' => '5121']);
    $ligne = makeLigneStub(['tiers_id' => null, 'debit' => '100.00', 'credit' => '0.00']);
    $ligne->setRelation('compte', $compte);

    $generator->assertPasDeTiersSur512(collect([$ligne]));

    expect(true)->toBeTrue();
});

test('assertPasDeTiersSur512 accepte ligne 5112 avec tiers (5112 ≠ 512X — chèques à encaisser)', function () {
    // 5112 commence par '511', pas '512' — le scope bancaires() filtre '512_%'
    // donc 5112 est un compte de portage lettrable, pas un compte bancaire 512X.
    $generator = app(EcritureGenerator::class);

    $compte = new Compte(['numero_pcg' => '5112']);
    $ligne = makeLigneStub(['tiers_id' => 3, 'debit' => '50.00', 'credit' => '0.00']);
    $ligne->setRelation('compte', $compte);

    $generator->assertPasDeTiersSur512(collect([$ligne]));

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 14 : generateLettrageCode retourne 20 chars unique
// ---------------------------------------------------------------------------
test('generateLettrageCode retourne une string de 20 caractères unique à chaque appel', function () {
    $generator = app(EcritureGenerator::class);

    $code1 = $generator->generateLettrageCode();
    $code2 = $generator->generateLettrageCode();

    expect($code1)->toBeString()->toHaveLength(20);
    expect($code2)->toBeString()->toHaveLength(20);
    expect($code1)->not->toBe($code2);
});
