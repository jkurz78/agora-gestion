<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
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
    $compteA = Compte::factory()->depense()->pourFraisKilometriques()->create([
        'association_id' => $assoA->id,
        'intitule' => 'Déplacements',
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
        'compte_id' => $compteA->id,
    ]);

    TenantContext::boot($assoB);

    // NDF scopée tenant → invisible depuis assoB
    expect(NoteDeFrais::query()->count())->toBe(0);

    // NoteDeFraisLigne n'a pas d'association_id propre : l'isolation est transitive via la FK note_de_frais_id.
    $visibleNdfIds = NoteDeFrais::query()->pluck('id');
    expect(NoteDeFraisLigne::whereIn('note_de_frais_id', $visibleNdfIds)->count())->toBe(0);

    // Compte scopé tenant → usage km invisible depuis assoB
    $visibleCompteIds = Compte::pluck('id');
    expect(DB::table('usages_sous_categories')
        ->whereIn('compte_id', $visibleCompteIds)
        ->where('usage', UsageComptable::FraisKilometriques->value)
        ->count())->toBe(0);
});

it('le pivot frais_kilometriques est scope-locked au tenant', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    Compte::factory()->depense()->pourFraisKilometriques()->create([
        'association_id' => $assoA->id,
        'intitule' => 'Déplacements A',
    ]);

    TenantContext::boot($assoB);
    Compte::factory()->depense()->create([
        'association_id' => $assoB->id,
        'intitule' => 'Bureau B',
    ]);

    // En contexte B : aucun compte avec usage km
    $visibleComptesB = Compte::pluck('id');
    $countB = DB::table('usages_sous_categories')
        ->whereIn('compte_id', $visibleComptesB)
        ->where('usage', UsageComptable::FraisKilometriques->value)
        ->count();
    expect($countB)->toBe(0);

    // En contexte A : 1 compte avec usage km
    TenantContext::boot($assoA);
    $visibleComptesA = Compte::pluck('id');
    $countA = DB::table('usages_sous_categories')
        ->whereIn('compte_id', $visibleComptesA)
        ->where('usage', UsageComptable::FraisKilometriques->value)
        ->count();
    expect($countA)->toBe(1);
});
