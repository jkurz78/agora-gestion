<?php

declare(strict_types=1);

use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
use App\Services\Compta\Migrations\FamillesSeeder;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * DC-1 — dissolution sous_categories → comptes.
 *
 * La table `familles` nomme un préfixe à 2 chiffres (classes 6/7 du PCG).
 * Le rattachement compte → famille est DÉRIVÉ par préfixe (pas de FK).
 * `FamillesSeeder::seed()` porte la logique de migration de données pour
 * pouvoir être rejouée en test sans dérouler la migration complète.
 */
it('migre les categories existantes en familles (parse "NN - Nom")', function () {
    $associationId = TenantContext::currentId();

    Categorie::factory()->create([
        'association_id' => $associationId,
        'nom' => '74 - Subventions',
        'type' => 'recette',
    ]);

    FamillesSeeder::seed();

    $famille = Famille::where('code', '74')->first();

    expect($famille)->not->toBeNull()
        ->and($famille->nom)->toBe('Subventions')
        ->and((int) $famille->association_id)->toBe((int) $associationId);
});

it('crée une famille code=nom pour les préfixes de comptes 6/7 sans catégorie', function () {
    $associationId = TenantContext::currentId();

    Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => '781',
        'intitule' => 'Reprises sur provisions',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    FamillesSeeder::seed();

    $famille = Famille::where('code', '78')->first();

    expect($famille)->not->toBeNull()
        ->and($famille->nom)->toBe('78');
});

it('ne duplique pas une famille existante (unique par association)', function () {
    $associationId = TenantContext::currentId();

    Categorie::factory()->create([
        'association_id' => $associationId,
        'nom' => '70 - Ventes et prestations',
        'type' => 'recette',
    ]);

    FamillesSeeder::seed();
    FamillesSeeder::seed();

    $count = Famille::where('code', '70')->count();

    expect($count)->toBe(1);
});

it('Famille::libelle et estDepense', function () {
    $depense = Famille::factory()->create(['code' => '61', 'nom' => 'Charges de fonctionnement']);
    $recette = Famille::factory()->create(['code' => '70', 'nom' => 'Ventes et prestations']);

    expect($depense->libelle())->toBe('61 — Charges de fonctionnement')
        ->and($depense->estDepense())->toBeTrue()
        ->and($recette->estDepense())->toBeFalse();
});

it('Compte::famille résout par préfixe', function () {
    $associationId = TenantContext::currentId();

    Famille::factory()->create(['association_id' => $associationId, 'code' => '70', 'nom' => 'Ventes et prestations']);

    $compte = Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => '706A',
        'intitule' => 'Prestations diverses',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    expect($compte->famille())->not->toBeNull()
        ->and($compte->famille()->nom)->toBe('Ventes et prestations');
});

it('la création d un compte 6/7 auto-crée la famille orpheline', function () {
    $associationId = TenantContext::currentId();

    expect(Famille::where('code', '79')->exists())->toBeFalse();

    Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => '79X',
        'intitule' => 'Compte de test',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $famille = Famille::where('code', '79')->first();

    expect($famille)->not->toBeNull()
        ->and($famille->nom)->toBe('79');
});

it('la création d un compte classe 5 n auto-crée pas de famille', function () {
    $associationId = TenantContext::currentId();

    Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => '5199',
        'intitule' => 'Compte bancaire test',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    expect(Famille::where('code', '51')->exists())->toBeFalse();
});

it('Famille::pourComptes charge groupé par code', function () {
    $associationId = TenantContext::currentId();

    Famille::factory()->create(['association_id' => $associationId, 'code' => '70', 'nom' => 'Ventes et prestations']);
    Famille::factory()->create(['association_id' => $associationId, 'code' => '74', 'nom' => 'Subventions']);

    $compte70 = Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => '706A',
        'intitule' => 'Prestations diverses',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $compte74 = Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => '7411',
        'intitule' => 'Subvention région',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $comptes = new Collection([$compte70, $compte74]);

    $familles = Famille::pourComptes($comptes);

    expect($familles)->toHaveCount(2)
        ->and($familles->get('70')->nom)->toBe('Ventes et prestations')
        ->and($familles->get('74')->nom)->toBe('Subventions');
});
