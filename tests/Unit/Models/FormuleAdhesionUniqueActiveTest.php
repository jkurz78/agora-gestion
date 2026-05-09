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
