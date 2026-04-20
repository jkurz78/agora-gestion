<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\NoteDeFrais;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);

    $this->cat = Categorie::factory()->create(['association_id' => $this->asso->id]);
    $this->scKm = SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    $this->service = app(NoteDeFraisService::class);
});

it('sauvegarde une ligne kilometrique avec type, metadata et montant calculé server-side', function () {
    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Paris-Rennes AG',
                'montant' => 99999.99, // tampering client ignoré
                'cv_fiscaux' => 5,
                'distance_km' => 420,
                'bareme_eur_km' => 0.636,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->type)->toBe(NoteDeFraisLigneType::Kilometrique);
    expect((float) $ligne->montant)->toBe(267.12);
    expect($ligne->metadata)->toBe([
        'cv_fiscaux' => 5,
        'distance_km' => 420,   // JSON round-trip: integer without fractional part
        'bareme_eur_km' => 0.636,
    ]);
    expect((int) $ligne->sous_categorie_id)->toBe((int) $this->scKm->id);
});

it('sauvegarde une ligne standard sans metadata', function () {
    $sc = SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Fournitures',
    ]);

    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'standard',
                'libelle' => 'Stylos',
                'montant' => 12.50,
                'sous_categorie_id' => $sc->id,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->type)->toBe(NoteDeFraisLigneType::Standard);
    expect((float) $ligne->montant)->toBe(12.50);
    expect($ligne->metadata)->toBeNull();
    expect((int) $ligne->sous_categorie_id)->toBe((int) $sc->id);
});

it('laisse sous_categorie_id à null pour ligne km si aucune sous-cat flaggée', function () {
    $this->scKm->update(['pour_frais_kilometriques' => false]);

    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Paris-Rennes AG',
                'montant' => 0,
                'cv_fiscaux' => 5,
                'distance_km' => 420,
                'bareme_eur_km' => 0.636,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->sous_categorie_id)->toBeNull();
});

it('laisse sous_categorie_id à null pour ligne km si deux sous-cat flaggées', function () {
    SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements bis',
        'pour_frais_kilometriques' => true,
    ]);

    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Paris-Rennes AG',
                'montant' => 0,
                'cv_fiscaux' => 5,
                'distance_km' => 420,
                'bareme_eur_km' => 0.636,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->sous_categorie_id)->toBeNull();
});

it('isolation tenant — flag flaggé dans asso A invisible pour asso B', function () {
    $assoB = Association::factory()->create();
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);

    TenantContext::boot($assoB);

    $ndf = $this->service->saveDraft($tiersB, [
        'date' => '2026-04-20',
        'libelle' => 'NDF B',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Trajet',
                'montant' => 0,
                'cv_fiscaux' => 5,
                'distance_km' => 100,
                'bareme_eur_km' => 0.5,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->sous_categorie_id)->toBeNull();
});
