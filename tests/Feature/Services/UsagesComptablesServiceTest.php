<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * DC-8 : le service accepte des ids de comptes (classe 6/7).
 */
function creerCompteVentilation(Association $asso, string $numeroPcg): Compte
{
    return Compte::factory()->numero($numeroPcg)->create([
        'association_id' => $asso->id,
        'classe' => (int) substr($numeroPcg, 0, 1),
    ]);
}

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->service = app(UsagesComptablesService::class);
});

it('setFraisKilometriques poses the link and removes previous', function () {
    $compte1 = creerCompteVentilation($this->asso, '625A');
    $compte2 = creerCompteVentilation($this->asso, '625B');

    $this->service->setFraisKilometriques($compte1->id);
    expect($compte1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    $this->service->setFraisKilometriques($compte2->id);
    expect($compte1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
    expect($compte2->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('setFraisKilometriques(null) clears', function () {
    $compte = creerCompteVentilation($this->asso, '625A');
    $this->service->setFraisKilometriques($compte->id);
    $this->service->setFraisKilometriques(null);
    expect($compte->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
});

it('toggleDon / toggleCotisation / toggleInscription are idempotent', function () {
    $compte = creerCompteVentilation($this->asso, '754A');
    $this->service->toggleDon($compte->id, true);
    $this->service->toggleDon($compte->id, true);
    expect($compte->fresh()->usages()->where('usage', UsageComptable::Don->value)->count())->toBe(1);

    $this->service->toggleDon($compte->id, false);
    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
});

it('setAbandonCreance on non-Don compte throws', function () {
    $compte = creerCompteVentilation($this->asso, '771A');
    expect(fn () => $this->service->setAbandonCreance($compte->id))->toThrow(DomainException::class);
});

it('setAbandonCreance on Don compte succeeds', function () {
    $compte = creerCompteVentilation($this->asso, '754A');
    $this->service->toggleDon($compte->id, true);
    $this->service->setAbandonCreance($compte->id);
    expect($compte->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();
});

it('toggleDon(false) cascades and removes AbandonCreance', function () {
    $compte = creerCompteVentilation($this->asso, '754A');
    $this->service->toggleDon($compte->id, true);
    $this->service->setAbandonCreance($compte->id);
    $this->service->toggleDon($compte->id, false);
    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
    expect($compte->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
});

it('createAndFlag creates a compte and posts the pivot link', function () {
    $compte = $this->service->createAndFlag([
        'intitule' => 'Nouveau compte',
        'numero_pcg' => '758A',
    ], UsageComptable::Cotisation);

    expect($compte)->toBeInstanceOf(Compte::class);
    expect($compte->hasUsage(UsageComptable::Cotisation))->toBeTrue();

});

it('createAndFlag throws on empty numero_pcg', function () {
    expect(fn () => $this->service->createAndFlag([
        'intitule' => 'Nouveau compte',
        'numero_pcg' => '',
    ], UsageComptable::Cotisation))->toThrow(DomainException::class);
});

it('createAndFlag throws on wrong-classe numero_pcg', function () {
    // Cotisation est une Recette (classe 7 attendue) — '606' est classe 6.
    expect(fn () => $this->service->createAndFlag([
        'intitule' => 'Nouveau compte',
        'numero_pcg' => '606',
    ], UsageComptable::Cotisation))->toThrow(DomainException::class);
});

it('createAndFlag reuses an existing compte with the same numero_pcg', function () {
    $existing = creerCompteVentilation($this->asso, '754A');

    $compte = $this->service->createAndFlag([
        'intitule' => 'Intitulé ignoré (compte déjà existant)',
        'numero_pcg' => '754A',
    ], UsageComptable::Cotisation);

    expect((int) $compte->id)->toBe((int) $existing->id);
    expect($compte->fresh()->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('createAndFlag(AbandonCreance) also posts Don', function () {
    $compte = $this->service->createAndFlag([
        'intitule' => 'Abandon de créance',
        'numero_pcg' => '771',
    ], UsageComptable::AbandonCreance);

    expect($compte->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($compte->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();

});

it('is tenant-scoped', function () {
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso2);
    $compte2 = creerCompteVentilation($asso2, '754A');

    TenantContext::boot($this->asso);
    expect(fn () => $this->service->toggleDon($compte2->id, true))->toThrow(ModelNotFoundException::class);
});
