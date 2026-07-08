<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;

/**
 * DC-8 — dissolution sous_categories → comptes.
 *
 * Couvre `Compte::usages()` / `hasUsage()` / `scopeForUsage()`, le pendant
 * `Compte` de ce qui existe déjà sur `SousCategorie` — la table pivot
 * `usages_sous_categories` reste la même, seule la colonne consultée change
 * (`compte_id` au lieu de `sous_categorie_id`).
 */
function creerCompteUsage(int $associationId, string $numeroPcg, int $classe = 7): Compte
{
    return Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => $numeroPcg,
        'intitule' => "Compte {$numeroPcg}",
        'classe' => $classe,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);
}

it('hasUsage retourne true quand le compte est flagué pour l\'usage', function () {
    $associationId = TenantContext::currentId();
    $compte = creerCompteUsage($associationId, '7061');

    UsageSousCategorie::create([
        'association_id' => $associationId,
        'compte_id' => $compte->id,
        'usage' => UsageComptable::Don->value,
    ]);

    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeTrue();
});

it('hasUsage retourne false quand le compte n\'est pas flagué pour l\'usage', function () {
    $associationId = TenantContext::currentId();
    $compte = creerCompteUsage($associationId, '7062');

    UsageSousCategorie::create([
        'association_id' => $associationId,
        'compte_id' => $compte->id,
        'usage' => UsageComptable::Cotisation->value,
    ]);

    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
});

it('scopeForUsage retourne uniquement les comptes flagues pour l\'usage donne', function () {
    $associationId = TenantContext::currentId();
    $compteDon = creerCompteUsage($associationId, '7063');
    $compteAutre = creerCompteUsage($associationId, '7064');

    UsageSousCategorie::create([
        'association_id' => $associationId,
        'compte_id' => $compteDon->id,
        'usage' => UsageComptable::Don->value,
    ]);

    $resultats = Compte::forUsage(UsageComptable::Don)->pluck('id');

    expect($resultats)->toContain($compteDon->id)
        ->and($resultats)->not->toContain($compteAutre->id);
});

it('scopeForUsage ne laisse pas fuiter les comptes d\'une autre association (isolation tenant)', function () {
    $associationId = TenantContext::currentId();
    $autreAssociation = Association::factory()->create();

    $compteIci = creerCompteUsage($associationId, '7065');
    UsageSousCategorie::create([
        'association_id' => $associationId,
        'compte_id' => $compteIci->id,
        'usage' => UsageComptable::Don->value,
    ]);

    TenantContext::boot($autreAssociation);
    $compteAilleurs = creerCompteUsage($autreAssociation->id, '7065');
    UsageSousCategorie::create([
        'association_id' => $autreAssociation->id,
        'compte_id' => $compteAilleurs->id,
        'usage' => UsageComptable::Don->value,
    ]);

    // Retour au tenant de départ : seul compteIci doit apparaître.
    TenantContext::boot(Association::find($associationId));
    $resultats = Compte::forUsage(UsageComptable::Don)->pluck('id');

    expect($resultats)->toContain($compteIci->id)
        ->and($resultats)->not->toContain($compteAilleurs->id);
});
