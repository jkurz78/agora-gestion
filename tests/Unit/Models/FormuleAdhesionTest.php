<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Tenant\TenantContext;

it('persiste une formule en mode exercice', function (): void {
    $sc = Compte::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Adhésion adulte 2025',
        'description' => 'Adhésion annuelle standard',
        'mode' => 'exercice',
        'duree_mois' => null,
        'montant_par_defaut' => 30.00,
        'deductible_fiscal' => false,
        'compte_id' => $sc->id,
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
    $sc = Compte::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::create([
        'association_id' => TenantContext::currentId(),
        'nom' => 'Adhésion glissante',
        'mode' => 'duree',
        'duree_mois' => 12,
        'montant_par_defaut' => 50.00,
        'deductible_fiscal' => true,
        'compte_id' => $sc->id,
        'actif' => true,
    ]);

    expect($formule->fresh()->mode)->toBe('duree');
    expect($formule->fresh()->duree_mois)->toBe(12);
    expect($formule->fresh()->deductible_fiscal)->toBeTrue();
    expect($formule->isModeDuree())->toBeTrue();
    expect($formule->isModeExercice())->toBeFalse();
});

it('expose la relation compte', function (): void {
    $sc = Compte::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['compte_id' => $sc->id]);

    expect($formule->compte->id)->toBe($sc->id);
});

it('respecte le scope tenant fail-closed', function (): void {
    $sc = Compte::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->create(['compte_id' => $sc->id]);

    TenantContext::clear();
    $autreAsso = Association::factory()->create();
    TenantContext::boot($autreAsso);

    expect(FormuleAdhesion::count())->toBe(0);
});

it('soft-delete préserve les données pour historique', function (): void {
    $sc = Compte::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['compte_id' => $sc->id]);

    $formule->delete();

    expect(FormuleAdhesion::count())->toBe(0);
    expect(FormuleAdhesion::withTrashed()->count())->toBe(1);
});

// Tests formuleAdhesionActive() sur SousCategorie — TRIAGE DC-10b-3 (relation SousCategorie)

it('caste duree_mois en int', function (): void {
    $sc = Compte::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create([
        'compte_id' => $sc->id,
        'mode' => 'duree',
        'duree_mois' => 6,
    ]);

    expect($formule->fresh()->duree_mois)->toBeInt();
    expect($formule->fresh()->duree_mois)->toBe(6);
});
