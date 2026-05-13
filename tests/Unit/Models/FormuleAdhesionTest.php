<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;

it('persiste une formule en mode exercice', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Adhésion adulte 2025',
        'description' => 'Adhésion annuelle standard',
        'mode' => 'exercice',
        'duree_mois' => null,
        'montant_par_defaut' => 30.00,
        'deductible_fiscal' => false,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->nom)->toBe('Adhésion adulte 2025');
    expect($formule->fresh()->mode)->toBe('exercice');
    expect($formule->fresh()->duree_mois)->toBeNull();
    expect((float) $formule->fresh()->montant_par_defaut)->toBe(30.00);
    expect($formule->fresh()->actif)->toBeTrue();
    expect($formule->fresh()->deductible_fiscal)->toBeFalse();
    expect($formule->isModeExercice())->toBeTrue();
    expect($formule->isModeDuree())->toBeFalse();
});

it('persiste une formule en mode durée 12 mois', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Adhésion glissante',
        'mode' => 'duree',
        'duree_mois' => 12,
        'montant_par_defaut' => 50.00,
        'deductible_fiscal' => true,
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->mode)->toBe('duree');
    expect($formule->fresh()->duree_mois)->toBe(12);
    expect($formule->fresh()->deductible_fiscal)->toBeTrue();
    expect($formule->isModeDuree())->toBeTrue();
    expect($formule->isModeExercice())->toBeFalse();
});

it('expose la relation sous-catégorie', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);

    expect($formule->sousCategorie->id)->toBe($sc->id);
});

it('expose la relation inverse depuis la sous-catégorie', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => false]);

    expect($sc->formulesAdhesion()->count())->toBe(2);
});

it('respecte le scope tenant fail-closed', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);

    TenantContext::clear();
    $autreAsso = Association::factory()->create();
    TenantContext::boot($autreAsso);

    expect(FormuleAdhesion::count())->toBe(0);
});

it('soft-delete préserve les données pour historique', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);

    $formule->delete();

    expect(FormuleAdhesion::count())->toBe(0);
    expect(FormuleAdhesion::withTrashed()->count())->toBe(1);
});

it('formuleAdhesionActive sur SousCategorie retourne uniquement la formule active', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => false]);
    $active = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => true]);

    expect($sc->formuleAdhesionActive()?->id)->toBe($active->id);
});

it('formuleAdhesionActive retourne null si aucune formule active', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => false]);

    expect($sc->formuleAdhesionActive())->toBeNull();
});

it('caste duree_mois en int', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'mode' => 'duree',
        'duree_mois' => 6,
    ]);

    expect($formule->fresh()->duree_mois)->toBeInt();
    expect($formule->fresh()->duree_mois)->toBe(6);
});
