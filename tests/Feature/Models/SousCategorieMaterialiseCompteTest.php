<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->catRecette = Categorie::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeCategorie::Recette,
    ]);
    $this->catDepense = Categorie::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeCategorie::Depense,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

function compte(string $numero, int $aid): ?Compte
{
    return Compte::withoutGlobalScopes()
        ->where('association_id', $aid)
        ->where('numero_pcg', $numero)
        ->first();
}

test('créer une sous-catégorie de recette matérialise son compte de classe 7', function () {
    $sc = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Prestations spéciales',
        'code_cerfa' => '799',
    ]);

    $compte = compte('799', (int) $this->association->id);
    expect($compte)->not->toBeNull();
    expect((int) $compte->classe)->toBe(7);
    expect($compte->intitule)->toBe('Prestations spéciales');
    expect((int) $compte->categorie_id)->toBe((int) $this->catRecette->id);
    expect((bool) $compte->actif)->toBeTrue();
    expect((bool) $compte->est_systeme)->toBeFalse();
    expect((bool) $compte->lettrable)->toBeFalse();
});

test('créer une sous-catégorie de dépense matérialise son compte de classe 6', function () {
    SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catDepense->id,
        'nom' => 'Achats spéciaux',
        'code_cerfa' => '605',
    ]);

    expect((int) compte('605', (int) $this->association->id)->classe)->toBe(6);
});

test('une sous-catégorie sans code ne crée aucun compte', function () {
    SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Sans code',
        'code_cerfa' => null,
    ]);

    expect(Compte::withoutGlobalScopes()->where('association_id', $this->association->id)->where('intitule', 'Sans code')->exists())->toBeFalse();
});

test('un code hors classe 6/7 ne crée pas de compte poubelle', function () {
    SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Code bilan',
        'code_cerfa' => '512',
    ]);
    SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Code lettres',
        'code_cerfa' => 'ABC',
    ]);

    expect(compte('512', (int) $this->association->id))->toBeNull();
    expect(compte('ABC', (int) $this->association->id))->toBeNull();
});

test('la matérialisation est idempotente (pas de doublon ni d\'erreur)', function () {
    SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Première',
        'code_cerfa' => '706',
    ]);
    // Deuxième sous-catégorie même code → pas de second compte, pas d'exception.
    SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Deuxième',
        'code_cerfa' => '706',
    ]);

    expect(Compte::withoutGlobalScopes()->where('association_id', $this->association->id)->where('numero_pcg', '706')->count())->toBe(1);
});

test('renseigner le code après coup (édition inline) matérialise le compte', function () {
    $sc = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Ajout tardif',
        'code_cerfa' => null,
    ]);
    expect(compte('758', (int) $this->association->id))->toBeNull();

    $sc->update(['code_cerfa' => '758']);

    expect(compte('758', (int) $this->association->id))->not->toBeNull();
});

test('un usage inscription donne pour_inscriptions=true au compte matérialisé', function () {
    $sc = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Formations',
        'code_cerfa' => null,
    ]);
    UsageSousCategorie::create([
        'association_id' => $this->association->id,
        'sous_categorie_id' => $sc->id,
        'usage' => UsageComptable::Inscription->value,
    ]);

    $sc->update(['code_cerfa' => '706']);

    expect((bool) compte('706', (int) $this->association->id)->pour_inscriptions)->toBeTrue();
});
