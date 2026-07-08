<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;

/**
 * DC-7 — dissolution sous_categories → comptes.
 *
 * Preuve de convergence du miroir bidirectionnel SousCategorie <-> Compte :
 *  - SousCategorieCompteObserver (SousCategorie → Compte, existant depuis DC-4)
 *  - CompteObserver::materialiserSousCategorie (Compte → SousCategorie, DC-7)
 *
 * Le point critique : les deux observers se re-déclenchent l'un l'autre
 * (SousCategorie::create → Compte::create → SousCategorie::create...) et ne
 * doivent JAMAIS boucler ni planter sur l'index unique
 * `comptes_asso_numero_pcg_unique`. Voir les docblocks des deux observers
 * pour la preuve d'ordonnancement Eloquent qui garantit cette convergence.
 *
 * Note : toutes les requêtes de vérification utilisent `withoutGlobalScopes()`
 * MAIS restent explicitement filtrées par `association_id` — le tenant de
 * test n'est pas le seul dans la base SQLite en mémoire partagée entre tests
 * (migrations + `SystemeSeeder` peuvent avoir matérialisé des comptes/familles
 * système 6/7 — ex. 681/781 — pour d'autres associations avant ce test).
 */
it('cree un Compte quand une SousCategorie 6/7 sans compte existant est creee (non-regression)', function () {
    $associationId = TenantContext::currentId();

    expect(Compte::withoutGlobalScopes()->where('association_id', $associationId)->where('numero_pcg', '706A')->exists())->toBeFalse();

    SousCategorie::factory()->create([
        'association_id' => $associationId,
        'code_cerfa' => '706A',
        'nom' => 'Formations',
    ]);

    $comptes = Compte::withoutGlobalScopes()->where('association_id', $associationId)->where('numero_pcg', '706A')->get();

    expect($comptes)->toHaveCount(1)
        ->and($comptes->first()->intitule)->toBe('Formations');
});

it('ne plante pas et ne duplique pas le Compte quand la SousCategorie pointe vers un code deja materialise', function () {
    $associationId = TenantContext::currentId();

    Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => '706A',
        'intitule' => 'Compte déjà existant',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $creation = fn () => SousCategorie::factory()->create([
        'association_id' => $associationId,
        'code_cerfa' => '706A',
        'nom' => 'Formations (doublon potentiel)',
    ]);

    expect($creation)->not->toThrow(Throwable::class);

    $comptes = Compte::withoutGlobalScopes()->where('association_id', $associationId)->where('numero_pcg', '706A')->get();

    expect($comptes)->toHaveCount(1)
        ->and($comptes->first()->intitule)->toBe('Compte déjà existant'); // pas écrasé
});

it('cree une SousCategorie miroir quand un Compte 6/7 non-systeme sans sous-categorie est cree, sans dupliquer le Compte', function () {
    $associationId = TenantContext::currentId();

    Categorie::factory()->create([
        'association_id' => $associationId,
        'nom' => '70 - Ventes et prestations',
        'type' => TypeCategorie::Recette->value,
    ]);

    expect(SousCategorie::withoutGlobalScopes()->where('association_id', $associationId)->where('code_cerfa', '706Z')->exists())->toBeFalse();

    Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => '706Z',
        'intitule' => 'Prestations diverses Z',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $sousCategories = SousCategorie::withoutGlobalScopes()->where('association_id', $associationId)->where('code_cerfa', '706Z')->get();

    expect($sousCategories)->toHaveCount(1)
        ->and($sousCategories->first()->nom)->toBe('Prestations diverses Z');

    // Round-trip complet : le SousCategorie::created ci-dessus a re-déclenché
    // SousCategorieCompteObserver, qui a retrouvé CE Compte déjà commité et n'a
    // rien recréé — pas de doublon, pas de crash sur l'index unique.
    $comptes = Compte::withoutGlobalScopes()->where('association_id', $associationId)->where('numero_pcg', '706Z')->get();
    expect($comptes)->toHaveCount(1);
});

it('cree une Categorie de secours "NN - Nom" quand la Famille du prefixe existe deja', function () {
    $associationId = TenantContext::currentId();

    Famille::factory()->create([
        'association_id' => $associationId,
        'code' => '79',
        'nom' => 'Transferts de charges',
    ]);

    expect(Categorie::withoutGlobalScopes()->where('association_id', $associationId)->where('nom', 'LIKE', '79 -%')->exists())->toBeFalse();

    Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => '791',
        'intitule' => 'Transfert de charges test',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $categorie = Categorie::withoutGlobalScopes()->where('association_id', $associationId)->where('nom', 'LIKE', '79 -%')->first();

    expect($categorie)->not->toBeNull()
        ->and($categorie->nom)->toBe('79 - Transferts de charges')
        ->and($categorie->type)->toBe(TypeCategorie::Recette);
});

it('cree une Categorie de secours "NN - NN" quand ni Categorie ni Famille n existent pour le prefixe', function () {
    $associationId = TenantContext::currentId();

    expect(Famille::withoutGlobalScopes()->where('association_id', $associationId)->where('code', '68')->exists())->toBeFalse();
    expect(Categorie::withoutGlobalScopes()->where('association_id', $associationId)->where('nom', 'LIKE', '68 -%')->exists())->toBeFalse();

    Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => '6811',
        'intitule' => 'Dotations aux amortissements test',
        'classe' => 6,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $categorie = Categorie::withoutGlobalScopes()->where('association_id', $associationId)->where('nom', 'LIKE', '68 -%')->first();

    expect($categorie)->not->toBeNull()
        ->and($categorie->nom)->toBe('68 - 68')
        ->and($categorie->type)->toBe(TypeCategorie::Depense);
});

it('ne cree pas de SousCategorie miroir pour un Compte classe 5 (bancaire)', function () {
    $associationId = TenantContext::currentId();

    Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => '51299',
        'intitule' => 'Compte bancaire test',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    expect(SousCategorie::withoutGlobalScopes()->where('association_id', $associationId)->where('code_cerfa', '51299')->exists())->toBeFalse();
});

it('ne cree pas de SousCategorie miroir pour un Compte systeme classe 6/7', function () {
    $associationId = TenantContext::currentId();

    Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => '709Z',
        'intitule' => 'Compte systeme test',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => true,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    expect(SousCategorie::withoutGlobalScopes()->where('association_id', $associationId)->where('code_cerfa', '709Z')->exists())->toBeFalse();
});
