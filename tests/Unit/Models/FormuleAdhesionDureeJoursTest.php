<?php

declare(strict_types=1);

use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;

it('la colonne duree_jours existe sur formules_adhesion', function (): void {
    expect(Schema::hasColumn('formules_adhesion', 'duree_jours'))->toBeTrue();
});

it('crée une formule mode=duree avec duree_mois=12 et duree_jours=null (OK)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Formule 12 mois',
        'mode' => 'duree',
        'duree_mois' => 12,
        'duree_jours' => null,
        'montant_par_defaut' => 50.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->duree_mois)->toBe(12);
    expect($formule->fresh()->duree_jours)->toBeNull();
});

it('crée une formule mode=duree avec duree_mois=null et duree_jours=10 (OK)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Formule 10 jours',
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => 10,
        'montant_par_defaut' => 50.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->duree_mois)->toBeNull();
    expect($formule->fresh()->duree_jours)->toBe(10);
});

it('refuse mode=duree avec duree_mois ET duree_jours tous les deux set (XOR)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    expect(function () use ($sc): void {
        FormuleAdhesion::create([
            'association_id' => TenantContext::currentId(),
            'nom' => 'Formule invalide',
            'mode' => 'duree',
            'duree_mois' => 12,
            'duree_jours' => 10,
            'montant_par_defaut' => 50.00,
            'deductible_fiscal' => false,
            'sous_categorie_id' => $sc->id,
            'actif' => true,
        ]);
    })->toThrow(DomainException::class, 'exactement une unité');
});

it('refuse mode=duree avec duree_mois ET duree_jours tous les deux null (XOR)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    expect(function () use ($sc): void {
        FormuleAdhesion::create([
            'association_id' => TenantContext::currentId(),
            'nom' => 'Formule sans durée',
            'mode' => 'duree',
            'duree_mois' => null,
            'duree_jours' => null,
            'montant_par_defaut' => 50.00,
            'deductible_fiscal' => false,
            'sous_categorie_id' => $sc->id,
            'actif' => true,
        ]);
    })->toThrow(DomainException::class, 'exactement une unité');
});

it('formule mode=exercice peut avoir duree_mois=null et duree_jours=null (XOR ne s\'applique pas)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Adhésion exercice',
        'mode' => 'exercice',
        'duree_mois' => null,
        'duree_jours' => null,
        'montant_par_defaut' => 30.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->duree_mois)->toBeNull();
    expect($formule->fresh()->duree_jours)->toBeNull();
});

it('formule mode=illimite peut avoir duree_mois=null et duree_jours=null (XOR ne s\'applique pas)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Membre à vie',
        'mode' => 'illimite',
        'duree_mois' => null,
        'duree_jours' => null,
        'montant_par_defaut' => 0.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->duree_mois)->toBeNull();
    expect($formule->fresh()->duree_jours)->toBeNull();
});

it('isUniteJours retourne true quand mode=duree et duree_jours set', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Formule jours',
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => 300,
        'montant_par_defaut' => 50.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->isUniteJours())->toBeTrue();
});

it('isUniteJours retourne false pour mode=duree avec duree_mois set', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Formule mois',
        'mode' => 'duree',
        'duree_mois' => 12,
        'duree_jours' => null,
        'montant_par_defaut' => 50.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->isUniteJours())->toBeFalse();
});

it('caste duree_jours en int', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Formule 300 jours',
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => 300,
        'montant_par_defaut' => 50.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->duree_jours)->toBeInt();
    expect($formule->fresh()->duree_jours)->toBe(300);
});
