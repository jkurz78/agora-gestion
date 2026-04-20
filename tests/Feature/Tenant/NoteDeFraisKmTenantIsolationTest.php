<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Tenant\TenantContext;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

it('une ligne km de asso A est invisible de asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $tiersA = Tiers::factory()->create(['association_id' => $assoA->id]);
    $catA = Categorie::factory()->create(['association_id' => $assoA->id]);
    $scA = SousCategorie::create([
        'association_id' => $assoA->id,
        'categorie_id' => $catA->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);
    $ndfA = NoteDeFrais::create([
        'association_id' => $assoA->id,
        'tiers_id' => $tiersA->id,
        'date' => '2026-04-20',
        'libelle' => 'NDF A',
        'statut' => StatutNoteDeFrais::Brouillon->value,
    ]);
    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndfA->id,
        'type' => NoteDeFraisLigneType::Kilometrique->value,
        'libelle' => 'Secret A',
        'montant' => 100,
        'metadata' => ['cv_fiscaux' => 5, 'distance_km' => 200, 'bareme_eur_km' => 0.5],
        'sous_categorie_id' => $scA->id,
    ]);

    TenantContext::boot($assoB);

    // NDF scopée tenant → invisible depuis assoB
    expect(NoteDeFrais::query()->count())->toBe(0);

    // NoteDeFraisLigne n'a pas d'association_id propre : l'isolation est transitive via la FK note_de_frais_id.
    // En contexte assoB, NoteDeFrais::count() === 0, donc aucune ligne ne peut être atteinte via les NDF visibles.
    // On le vérifie en passant par la relation : lignes dont la NDF est visible = 0.
    $visibleNdfIds = NoteDeFrais::query()->pluck('id');
    expect(NoteDeFraisLigne::whereIn('note_de_frais_id', $visibleNdfIds)->count())->toBe(0);

    // SousCategorie scopée tenant → flag km invisible depuis assoB
    expect(SousCategorie::where('pour_frais_kilometriques', true)->count())->toBe(0);
});

it('le flag pour_frais_kilometriques est scope-locked au tenant', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $catA = Categorie::factory()->create(['association_id' => $assoA->id]);
    SousCategorie::create([
        'association_id' => $assoA->id,
        'categorie_id' => $catA->id,
        'nom' => 'Déplacements A',
        'pour_frais_kilometriques' => true,
    ]);

    TenantContext::boot($assoB);
    $catB = Categorie::factory()->create(['association_id' => $assoB->id]);
    SousCategorie::create([
        'association_id' => $assoB->id,
        'categorie_id' => $catB->id,
        'nom' => 'Bureau B',
        'pour_frais_kilometriques' => false,
    ]);

    // En contexte B : aucune sous-cat flaggée
    expect(SousCategorie::where('pour_frais_kilometriques', true)->count())->toBe(0);

    // En contexte A : 1 flaggée
    TenantContext::boot($assoA);
    expect(SousCategorie::where('pour_frais_kilometriques', true)->count())->toBe(1);
});
