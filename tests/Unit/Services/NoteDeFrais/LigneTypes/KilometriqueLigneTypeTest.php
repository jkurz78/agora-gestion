<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Services\NoteDeFrais\LigneTypes\KilometriqueLigneType;
use App\Tenant\TenantContext;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->strategy = new KilometriqueLigneType;
});

it('retourne la clé Kilometrique', function () {
    expect($this->strategy->key())->toBe(NoteDeFraisLigneType::Kilometrique);
});

it('calcule montant = distance x bareme, arrondi 2 décimales', function () {
    $montant = $this->strategy->computeMontant([
        'distance_km' => 420,
        'bareme_eur_km' => 0.636,
    ]);
    expect($montant)->toBe(267.12);
});

it('arrondit half-up le montant', function () {
    $montant = $this->strategy->computeMontant([
        'distance_km' => 10,
        'bareme_eur_km' => 0.15,
    ]);
    expect($montant)->toBe(1.5);
});

it('accepte les virgules françaises dans les champs', function () {
    $montant = $this->strategy->computeMontant([
        'distance_km' => '420,5',
        'bareme_eur_km' => '0,636',
    ]);
    expect($montant)->toBe(round(420.5 * 0.636, 2));
});

it('metadata contient cv_fiscaux, distance_km, bareme_eur_km', function () {
    $metadata = $this->strategy->metadata([
        'cv_fiscaux' => '5',
        'distance_km' => '420',
        'bareme_eur_km' => '0,636',
    ]);
    expect($metadata)->toBe([
        'cv_fiscaux' => 5,
        'distance_km' => 420.0,
        'bareme_eur_km' => 0.636,
    ]);
});

it('renderDescription formate la phrase française', function () {
    $desc = $this->strategy->renderDescription([
        'cv_fiscaux' => 5,
        'distance_km' => 420,
        'bareme_eur_km' => 0.636,
    ]);
    expect($desc)->toBe('Déplacement de 420 km avec un véhicule 5 CV au barème de 0,636 €/km');
});

it('renderDescription retourne chaine vide si metadata vide', function () {
    expect($this->strategy->renderDescription([]))->toBe('');
});

it('validate lève si cv_fiscaux manquant ou invalide', function () {
    expect(fn () => $this->strategy->validate([
        'distance_km' => 100,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 0,
        'distance_km' => 100,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 100,
        'distance_km' => 100,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);
});

it('validate lève si distance_km <= 0', function () {
    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 5,
        'distance_km' => 0,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);
});

it('validate lève si bareme_eur_km <= 0', function () {
    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 5,
        'distance_km' => 100,
        'bareme_eur_km' => 0,
    ]))->toThrow(ValidationException::class);
});

it('validate passe pour un draft km complet valide', function () {
    $this->strategy->validate([
        'cv_fiscaux' => 5,
        'distance_km' => 420,
        'bareme_eur_km' => 0.636,
    ]);
    expect(true)->toBeTrue();
});

it('resolveSousCategorieId retourne null si aucune sous-cat flaggée', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    expect($this->strategy->resolveSousCategorieId(null))->toBeNull();
});

it('resolveSousCategorieId retourne l\'id unique si exactement une flaggée', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $cat = Categorie::factory()->create(['association_id' => $asso->id]);
    $sc = SousCategorie::factory()->pourFraisKilometriques()->create([
        'association_id' => $asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements',
    ]);

    expect($this->strategy->resolveSousCategorieId(null))->toBe($sc->id);
});

it('resolveSousCategorieId retourne null si plusieurs flaggées', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $cat = Categorie::factory()->create(['association_id' => $asso->id]);
    SousCategorie::factory()->pourFraisKilometriques()->create([
        'association_id' => $asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements bénévoles',
    ]);
    SousCategorie::factory()->pourFraisKilometriques()->create([
        'association_id' => $asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements salariés',
    ]);

    expect($this->strategy->resolveSousCategorieId(null))->toBeNull();
});
