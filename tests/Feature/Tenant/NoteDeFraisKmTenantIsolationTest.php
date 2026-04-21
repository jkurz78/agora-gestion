<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

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
    $scA = SousCategorie::factory()->pourFraisKilometriques()->create([
        'association_id' => $assoA->id,
        'categorie_id' => $catA->id,
        'nom' => 'Déplacements',
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
    $visibleNdfIds = NoteDeFrais::query()->pluck('id');
    expect(NoteDeFraisLigne::whereIn('note_de_frais_id', $visibleNdfIds)->count())->toBe(0);

    // SousCategorie scopée tenant → usage km invisible depuis assoB
    $visibleScIds = SousCategorie::pluck('id');
    expect(DB::table('usages_sous_categories')
        ->whereIn('sous_categorie_id', $visibleScIds)
        ->where('usage', UsageComptable::FraisKilometriques->value)
        ->count())->toBe(0);
});

it('le pivot frais_kilometriques est scope-locked au tenant', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $catA = Categorie::factory()->create(['association_id' => $assoA->id]);
    SousCategorie::factory()->pourFraisKilometriques()->create([
        'association_id' => $assoA->id,
        'categorie_id' => $catA->id,
        'nom' => 'Déplacements A',
    ]);

    TenantContext::boot($assoB);
    $catB = Categorie::factory()->create(['association_id' => $assoB->id]);
    SousCategorie::factory()->create([
        'association_id' => $assoB->id,
        'categorie_id' => $catB->id,
        'nom' => 'Bureau B',
    ]);

    // En contexte B : aucune sous-cat avec usage km
    $visibleScIdsB = SousCategorie::pluck('id');
    $countB = DB::table('usages_sous_categories')
        ->whereIn('sous_categorie_id', $visibleScIdsB)
        ->where('usage', UsageComptable::FraisKilometriques->value)
        ->count();
    expect($countB)->toBe(0);

    // En contexte A : 1 sous-cat avec usage km
    TenantContext::boot($assoA);
    $visibleScIdsA = SousCategorie::pluck('id');
    $countA = DB::table('usages_sous_categories')
        ->whereIn('sous_categorie_id', $visibleScIdsA)
        ->where('usage', UsageComptable::FraisKilometriques->value)
        ->count();
    expect($countA)->toBe(1);
});
