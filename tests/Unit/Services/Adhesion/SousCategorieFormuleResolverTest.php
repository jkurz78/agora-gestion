<?php

declare(strict_types=1);

use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Services\Adhesion\SousCategorieFormuleResolver;

it('résout la formule active de la sous-catégorie', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => true]);

    $resolver = new SousCategorieFormuleResolver;
    $result = $resolver->resolve($sc->id);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($formule->id);
});

it('retourne null si aucune formule active', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    FormuleAdhesion::factory()->inactif()->create(['sous_categorie_id' => $sc->id]);

    $resolver = new SousCategorieFormuleResolver;
    $result = $resolver->resolve($sc->id);

    expect($result)->toBeNull();
});

it('retourne null si la sous-cat n\'a aucune formule', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $resolver = new SousCategorieFormuleResolver;
    $result = $resolver->resolve($sc->id);

    expect($result)->toBeNull();
});

it('ignore les formules HelloAsso (priorité 2 = formules manuelles uniquement)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();

    // Formule HelloAsso active sur la sous-cat → ignorée par priorité 2
    FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-2026',
        'helloasso_tier_id' => 1,
    ]);

    $resolver = new SousCategorieFormuleResolver;
    expect($resolver->resolve($sc->id))->toBeNull();

    // Ajout d'une formule manuelle → c'est elle qui doit être retournée
    $manuelle = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'actif' => true,
        'est_helloasso' => false,
    ]);

    $resolver2 = new SousCategorieFormuleResolver;
    expect($resolver2->resolve($sc->id)?->id)->toBe($manuelle->id);
});

it('cache le résultat sur la même instance (pas de double query)', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id, 'actif' => true]);

    $resolver = new SousCategorieFormuleResolver;

    // First resolve — populates cache
    $first = $resolver->resolve($sc->id);
    expect($first)->not->toBeNull();
    expect($first->id)->toBe($formule->id);

    // Mutate DB: mark formule inactive
    $formule->update(['actif' => false]);

    // Second resolve — must return cached formule (still active in cache)
    $second = $resolver->resolve($sc->id);
    expect($second)->not->toBeNull();
    expect($second->id)->toBe($formule->id);
});
