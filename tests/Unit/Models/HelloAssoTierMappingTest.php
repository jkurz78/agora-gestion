<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoTierMapping;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;

it('persiste un mapping vers une formule', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);

    $mapping = HelloAssoTierMapping::create([
        'association_id' => TenantContext::currentId(),
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 12345,
        'helloasso_tier_label' => 'Adulte',
        'target_type' => FormuleAdhesion::class,
        'target_id' => $formule->id,
    ]);

    expect($mapping->fresh()->helloasso_tier_id)->toBe(12345);
    expect($mapping->fresh()->helloasso_tier_label)->toBe('Adulte');
});

it('résout la cible polymorphe vers FormuleAdhesion', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);

    $mapping = HelloAssoTierMapping::factory()->create([
        'target_type' => FormuleAdhesion::class,
        'target_id' => $formule->id,
    ]);

    expect($mapping->target)->toBeInstanceOf(FormuleAdhesion::class);
    expect($mapping->target->id)->toBe($formule->id);
});

it('expose la relation inverse morphMany depuis FormuleAdhesion', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);

    HelloAssoTierMapping::factory()->create([
        'target_type' => FormuleAdhesion::class,
        'target_id' => $formule->id,
        'helloasso_tier_id' => 1,
    ]);
    HelloAssoTierMapping::factory()->create([
        'target_type' => FormuleAdhesion::class,
        'target_id' => $formule->id,
        'helloasso_tier_id' => 2,
    ]);

    expect($formule->fresh()->helloAssoTierMappings)->toHaveCount(2);
});

it('refuse un doublon (form_slug, tier_id) sur la même asso', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);

    HelloAssoTierMapping::factory()->create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 99,
        'target_type' => FormuleAdhesion::class,
        'target_id' => $formule->id,
    ]);

    expect(fn () => HelloAssoTierMapping::factory()->create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 99,
        'target_type' => FormuleAdhesion::class,
        'target_id' => $formule->id,
    ]))->toThrow(QueryException::class);
});

it('respecte le scope tenant fail-closed', function (): void {
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $sc->id]);
    HelloAssoTierMapping::factory()->create([
        'target_type' => FormuleAdhesion::class,
        'target_id' => $formule->id,
    ]);

    TenantContext::clear();
    $autreAsso = Association::factory()->create();
    TenantContext::boot($autreAsso);

    expect(HelloAssoTierMapping::count())->toBe(0);
});
