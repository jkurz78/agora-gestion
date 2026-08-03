<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoParametres;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
    TenantContext::boot($association);

    $this->parametres = HelloAssoParametres::factory()->create(['association_id' => 1]);
    $this->compte = Compte::factory()->pourCotisations()->create();
});

it('désactive les formules HelloAsso orphelines', function (): void {
    $formuleVivante = FormuleAdhesion::factory()->create([
        'compte_id' => $this->compte->id,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-active',
        'helloasso_tier_id' => 1,
        'actif' => true,
    ]);

    // Créer la formule orpheline sur un compte séparé pour éviter
    // la contrainte "1 formule active par compte"
    $compteOrpheline = Compte::factory()->pourCotisations()->create();
    $formuleOrpheline = FormuleAdhesion::factory()->create([
        'compte_id' => $compteOrpheline->id,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-disparue',
        'helloasso_tier_id' => 1,
        'actif' => true,
    ]);

    $compteManuel = Compte::factory()->pourCotisations()->create();
    $formuleManuelle = FormuleAdhesion::factory()->create([
        'compte_id' => $compteManuel->id,
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
