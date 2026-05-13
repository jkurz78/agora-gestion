<?php

declare(strict_types=1);

use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;

it('refuse l\'activation d\'une 2e formule sur la même sous-catégorie', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => true]);

    expect(fn () => FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]))->toThrow(DomainException::class, 'déjà une formule active');
});

it('autorise une 2e formule inactive sur la même sous-cat', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => true]);

    $secondInactive = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => false,
    ]);

    expect($secondInactive->id)->toBeInt();
});

it('autorise l\'activation après désactivation de l\'ancienne', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $ancienne = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);
    $ancienne->update(['actif' => false]);

    $nouvelle = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    expect($nouvelle->id)->toBeInt();
});

it('autorise l\'édition de la formule active elle-même (pas un faux doublon)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
    ]);

    $formule->update(['nom' => 'Nom modifié']);

    expect($formule->fresh()->nom)->toBe('Nom modifié');
});

it('autorise N formules HelloAsso actives sur la même sous-cat (4 paliers d\'un form Membership)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    // 1 formule manuelle active
    FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
        'est_helloasso' => false,
        'nom' => 'Adhésion manuelle',
    ]);

    // N formules HelloAsso actives sur la même sous-cat — autorisé
    foreach ([['Adulte', 1], ['Étudiant', 2], ['Bienfaiteur', 3], ['Famille', 4]] as [$nom, $tierId]) {
        $formule = FormuleAdhesion::factory()->create([
            'sous_categorie_id' => $sc->id,
            'actif' => true,
            'est_helloasso' => true,
            'helloasso_form_slug' => 'cotisation-2026',
            'helloasso_tier_id' => $tierId,
            'nom' => $nom,
        ]);
        expect($formule->id)->toBeInt();
    }

    expect(FormuleAdhesion::where('sous_categorie_id', $sc->id)->where('actif', true)->count())->toBe(5);
});

it('refuse une 2e formule MANUELLE active même si une HelloAsso existe sur la sous-cat', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-2026',
        'helloasso_tier_id' => 1,
    ]);

    FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
        'est_helloasso' => false,
    ]);

    // 2e manuelle active : refusée
    expect(fn () => FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
        'est_helloasso' => false,
    ]))->toThrow(DomainException::class, 'déjà une formule active');
});
