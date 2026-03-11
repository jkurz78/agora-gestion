<?php

use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\RapportService;

beforeEach(function () {
    $this->service = new RapportService;
    $this->user = User::factory()->create();

    $this->depenseCategorie = Categorie::factory()->depense()->create();
    $this->recetteCategorie = Categorie::factory()->recette()->create();
});

it('aggregates compte de resultat by code_cerfa', function () {
    $sc1 = SousCategorie::factory()->create([
        'categorie_id' => $this->depenseCategorie->id,
        'nom' => 'Fournitures',
        'code_cerfa' => '60',
    ]);
    $sc2 = SousCategorie::factory()->create([
        'categorie_id' => $this->recetteCategorie->id,
        'nom' => 'Adhésions',
        'code_cerfa' => '75',
    ]);

    // Depense in exercice 2025
    $depense = Depense::factory()->create([
        'date' => '2025-11-15',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc1->id,
        'montant' => 150.00,
    ]);
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc1->id,
        'montant' => 50.00,
    ]);

    // Recette in exercice 2025
    $recette = Recette::factory()->create([
        'date' => '2025-12-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $sc2->id,
        'montant' => 500.00,
    ]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'])->toHaveCount(1);
    expect($result['charges'][0]['code_cerfa'])->toBe('60');
    expect($result['charges'][0]['label'])->toBe('Fournitures');
    expect($result['charges'][0]['montant'])->toBe(200.0);

    expect($result['produits'])->toHaveCount(1);
    expect($result['produits'][0]['code_cerfa'])->toBe('75');
    expect($result['produits'][0]['montant'])->toBe(500.0);
});

it('filters compte de resultat by operations', function () {
    $operation = Operation::factory()->create();
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->depenseCategorie->id,
        'nom' => 'Frais transport',
    ]);

    $depense = Depense::factory()->create([
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();

    // With operation
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $operation->id,
        'montant' => 100.00,
    ]);

    // Without operation
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => null,
        'montant' => 200.00,
    ]);

    // Filter by operation
    $result = $this->service->compteDeResultat(2025, [$operation->id]);

    expect($result['charges'])->toHaveCount(1);
    expect($result['charges'][0]['montant'])->toBe(100.0);
});

it('builds rapport seances pivot table', function () {
    $operation = Operation::factory()->withSeances(3)->create();

    $scDepense = SousCategorie::factory()->create([
        'categorie_id' => $this->depenseCategorie->id,
        'nom' => 'Location salle',
    ]);
    $scRecette = SousCategorie::factory()->create([
        'categorie_id' => $this->recetteCategorie->id,
        'nom' => 'Inscriptions',
    ]);

    $depense = Depense::factory()->create([
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();

    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $scDepense->id,
        'operation_id' => $operation->id,
        'seance' => 1,
        'montant' => 100.00,
    ]);
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $scDepense->id,
        'operation_id' => $operation->id,
        'seance' => 2,
        'montant' => 150.00,
    ]);

    $recette = Recette::factory()->create([
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $scRecette->id,
        'operation_id' => $operation->id,
        'seance' => 1,
        'montant' => 300.00,
    ]);

    $result = $this->service->rapportSeances($operation->id);

    expect($result)->toHaveCount(2);

    $depenseRow = collect($result)->firstWhere('type', 'depense');
    expect($depenseRow['sous_categorie'])->toBe('Location salle');
    expect($depenseRow['seances'][1])->toBe(100.0);
    expect($depenseRow['seances'][2])->toBe(150.0);
    expect($depenseRow['total'])->toBe(250.0);

    $recetteRow = collect($result)->firstWhere('type', 'recette');
    expect($recetteRow['sous_categorie'])->toBe('Inscriptions');
    expect($recetteRow['seances'][1])->toBe(300.0);
    expect($recetteRow['total'])->toBe(300.0);
});

it('generates valid CSV output', function () {
    $rows = [
        ['A', 'B', '100,00'],
        ['C', 'D', '200,00'],
    ];

    $csv = $this->service->toCsv($rows, ['Col1', 'Col2', 'Montant']);

    expect($csv)->toContain('Col1;Col2;Montant');
    expect($csv)->toContain('A;B;');
    expect($csv)->toContain('C;D;');
});
