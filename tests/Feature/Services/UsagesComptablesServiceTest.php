<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->catR = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->catD = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Depense]);
    $this->service = app(UsagesComptablesService::class);
});

it('setFraisKilometriques poses the link and removes previous', function () {
    $sc1 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    $sc2 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();

    $this->service->setFraisKilometriques($sc1->id);
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    $this->service->setFraisKilometriques($sc2->id);
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
    expect($sc2->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('setFraisKilometriques(null) clears', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    $this->service->setFraisKilometriques($sc->id);
    $this->service->setFraisKilometriques(null);
    expect($sc->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
});

it('toggleDon / toggleCotisation / toggleInscription are idempotent', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $this->service->toggleDon($sc->id, true);
    $this->service->toggleDon($sc->id, true);
    expect($sc->fresh()->usages()->where('usage', UsageComptable::Don->value)->count())->toBe(1);

    $this->service->toggleDon($sc->id, false);
    expect($sc->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
});

it('setAbandonCreance on non-Don sous-cat throws', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    expect(fn () => $this->service->setAbandonCreance($sc->id))->toThrow(DomainException::class);
});

it('setAbandonCreance on Don sous-cat succeeds', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $this->service->toggleDon($sc->id, true);
    $this->service->setAbandonCreance($sc->id);
    expect($sc->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();
});

it('toggleDon(false) cascades and removes AbandonCreance', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $this->service->toggleDon($sc->id, true);
    $this->service->setAbandonCreance($sc->id);
    $this->service->toggleDon($sc->id, false);
    expect($sc->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
    expect($sc->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
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
});

it('is tenant-scoped', function () {
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso2);
    $catR2 = Categorie::factory()->for($asso2, 'association')->create(['type' => TypeCategorie::Recette]);
    $sc2 = SousCategorie::factory()->for($asso2, 'association')->for($catR2)->create();

    TenantContext::boot($this->asso);
    expect(fn () => $this->service->toggleDon($sc2->id, true))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
