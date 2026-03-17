<?php

use App\Models\CompteBancaire;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\DepenseLigneAffectation;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\RecetteLigneAffectation;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\RapportService;
use App\Enums\TypeCategorie;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(RapportService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->op1 = Operation::factory()->create();
    $this->categorie = Categorie::factory()->create(['type' => TypeCategorie::Recette]);
    $this->sousCategorie = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);
});

it('le rapport onglet 2 prend en compte les affectations au lieu de operation_id ligne', function () {
    // Recette de 20 000 sans opération directe
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => null,
        'montant' => 20000.00,
    ]);

    // Affectation de 8000 à op1
    RecetteLigneAffectation::create([
        'recette_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 8000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    // $rapport['produits'] est une liste de catégories, chaque catégorie ayant une clé 'sous_categories'.
    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    // Le rapport doit voir 8000 sur op1, pas 0 (car la ligne avait operation_id null)
    expect((float) ($scRow['montant'] ?? 0))->toBe(8000.0);
});

it('une ligne sans affectation continue d\'utiliser son operation_id direct', function () {
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 5000.00,
    ]);
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => $this->op1->id,
        'montant' => 5000.00,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 2 prend en compte les affectations de dépenses', function () {
    $categorieD = \App\Models\Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD   = \App\Models\SousCategorie::factory()->create(['categorie_id' => $categorieD->id]);
    $compte     = \App\Models\CompteBancaire::factory()->create();

    $depense = \App\Models\Depense::factory()->create([
        'compte_id'    => $compte->id,
        'date'         => '2025-10-15',
        'montant_total' => 12000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = \App\Models\DepenseLigne::factory()->create([
        'depense_id'       => $depense->id,
        'sous_categorie_id' => $sousCatD->id,
        'operation_id'     => null,
        'montant'          => 12000.00,
    ]);

    \App\Models\DepenseLigneAffectation::create([
        'depense_ligne_id' => $ligne->id,
        'operation_id'     => $this->op1->id,
        'montant'          => 7000.00,
        'seance'           => null,
        'notes'            => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $charges = collect($rapport['charges'] ?? []);
    $cat = $charges->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $sousCatD->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $sousCatD->id);
    expect((float) ($scRow['montant'] ?? 0))->toBe(7000.0);
});

it('le rapport onglet 3 prend en compte les affectations de recettes avec séance', function () {
    $recette = \App\Models\Recette::factory()->create([
        'compte_id'    => $this->compte->id,
        'date'         => '2025-10-15',
        'montant_total' => 3000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = \App\Models\RecetteLigne::factory()->create([
        'recette_id'       => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id'     => null,
        'seance'           => null,
        'montant'          => 3000.00,
    ]);

    RecetteLigneAffectation::create([
        'recette_ligne_id' => $ligne->id,
        'operation_id'     => $this->op1->id,
        'seance'           => 2,
        'montant'          => 3000.00,
        'notes'            => null,
    ]);

    $rapport = $this->service->rapportSeances(2025, [$this->op1->id]);

    // rapportSeances retourne ['seances' => [...], 'charges' => [...], 'produits' => [...]]
    // 'produits' est une liste de catégories, chacune avec 'sous_categories'
    // et chaque sous-catégorie a une clé 'seances' = [seance_num => montant]
    expect($rapport['seances'])->toContain(2);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    expect((float) ($scRow['seances'][2] ?? 0))->toBe(3000.0);
});
