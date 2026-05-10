<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
    TenantContext::boot($association);

    $this->parametres = HelloAssoParametres::factory()->create(['association_id' => 1]);
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
});

it('désactive les formules HelloAsso orphelines', function (): void {
    $formuleVivante = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-active',
        'helloasso_tier_id' => 1,
        'actif' => true,
    ]);

    // Créer la formule orpheline sur une sous-cat séparée pour éviter
    // la contrainte "1 formule active par sous-cat"
    $scOrpheline = SousCategorie::factory()->pourCotisations()->create();
    $formuleOrpheline = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $scOrpheline->id,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-disparue',
        'helloasso_tier_id' => 1,
        'actif' => true,
    ]);

    $scManuelle = SousCategorie::factory()->pourCotisations()->create();
    $formuleManuelle = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $scManuelle->id,
        'est_helloasso' => false,
        'actif' => true,
    ]);

    $service = new HelloAssoSyncService($this->parametres);
    $count = $service->desactiverFormulesOrphelines(['cotisation-active']);

    expect($count)->toBe(1);
    expect($formuleVivante->fresh()->actif)->toBeTrue();
    expect($formuleOrpheline->fresh()->actif)->toBeFalse();
    expect($formuleManuelle->fresh()->actif)->toBeTrue();
});
