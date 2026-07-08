<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * DC-8 : le service accepte désormais des ids de comptes (classe 6/7).
 * Les fixtures créent une SousCategorie avec code_cerfa — l'observer DC-7
 * matérialise le Compte miroir, et le trait SyncCompteDepuisSousCategorie
 * remplit sous_categorie_id (NOT NULL) sur les liens d'usage écrits par compte_id.
 */
function creerCompteVentilation(Association $asso, Categorie $cat, string $codeCerfa): Compte
{
    SousCategorie::factory()->for($asso, 'association')->for($cat)->create(['code_cerfa' => $codeCerfa]);

    return Compte::where('association_id', $asso->id)->where('numero_pcg', $codeCerfa)->firstOrFail();
}

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->catR = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->catD = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Depense]);
    $this->service = app(UsagesComptablesService::class);
});

it('setFraisKilometriques poses the link and removes previous', function () {
    $compte1 = creerCompteVentilation($this->asso, $this->catD, '625A');
    $compte2 = creerCompteVentilation($this->asso, $this->catD, '625B');

    $this->service->setFraisKilometriques($compte1->id);
    expect($compte1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    $this->service->setFraisKilometriques($compte2->id);
    expect($compte1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
    expect($compte2->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('setFraisKilometriques(null) clears', function () {
    $compte = creerCompteVentilation($this->asso, $this->catD, '625A');
    $this->service->setFraisKilometriques($compte->id);
    $this->service->setFraisKilometriques(null);
    expect($compte->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
});

it('toggleDon / toggleCotisation / toggleInscription are idempotent', function () {
    $compte = creerCompteVentilation($this->asso, $this->catR, '754A');
    $this->service->toggleDon($compte->id, true);
    $this->service->toggleDon($compte->id, true);
    expect($compte->fresh()->usages()->where('usage', UsageComptable::Don->value)->count())->toBe(1);

    $this->service->toggleDon($compte->id, false);
    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
});

it('setAbandonCreance on non-Don compte throws', function () {
    $compte = creerCompteVentilation($this->asso, $this->catR, '771A');
    expect(fn () => $this->service->setAbandonCreance($compte->id))->toThrow(DomainException::class);
});

it('setAbandonCreance on Don compte succeeds', function () {
    $compte = creerCompteVentilation($this->asso, $this->catR, '754A');
    $this->service->toggleDon($compte->id, true);
    $this->service->setAbandonCreance($compte->id);
    expect($compte->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();
});

it('toggleDon(false) cascades and removes AbandonCreance', function () {
    $compte = creerCompteVentilation($this->asso, $this->catR, '754A');
    $this->service->toggleDon($compte->id, true);
    $this->service->setAbandonCreance($compte->id);
    $this->service->toggleDon($compte->id, false);
    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
    expect($compte->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
});

it('createAndFlag creates sous-cat and posts the pivot link', function () {
    $sc = $this->service->createAndFlag([
        'categorie_id' => $this->catR->id,
        'nom' => 'Nouvelle sous-cat',
        'code_cerfa' => null,
    ], UsageComptable::Cotisation);

    expect($sc)->toBeInstanceOf(SousCategorie::class);
    expect($sc->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('createAndFlag(AbandonCreance) also posts Don', function () {
    $sc = $this->service->createAndFlag([
        'categorie_id' => $this->catR->id,
        'nom' => 'Abandon de créance',
        'code_cerfa' => '771',
    ], UsageComptable::AbandonCreance);

    expect($sc->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($sc->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();

    // DC-8 : le lien d'usage porte aussi compte_id (miroir rempli par le trait) —
    // le compte matérialisé est visible via Compte::forUsage().
    $compte = Compte::where('association_id', $this->asso->id)->where('numero_pcg', '771')->firstOrFail();
    expect($compte->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($compte->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();
});

it('is tenant-scoped', function () {
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso2);
    $catR2 = Categorie::factory()->for($asso2, 'association')->create(['type' => TypeCategorie::Recette]);
    $compte2 = creerCompteVentilation($asso2, $catR2, '754A');

    TenantContext::boot($this->asso);
    expect(fn () => $this->service->toggleDon($compte2->id, true))->toThrow(ModelNotFoundException::class);
});
