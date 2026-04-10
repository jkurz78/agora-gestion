<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Categorie;
use App\Models\Provision;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\ProvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->categorie = Categorie::factory()->create(['nom' => 'Charges externes']);
    $this->scLoyer = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'nom' => 'Loyer',
    ]);
    $this->scSubvention = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'nom' => 'Subventions',
    ]);
    $this->service = app(ProvisionService::class);
});

it('returns provisions grouped by sous-categorie for exercice N', function () {
    Provision::factory()->create([
        'exercice' => 2025,
        'type' => TypeTransaction::Depense,
        'sous_categorie_id' => $this->scLoyer->id,
        'libelle' => 'FNP Loyer',
        'montant' => 500.00,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
    ]);

    Provision::factory()->create([
        'exercice' => 2025,
        'type' => TypeTransaction::Recette,
        'sous_categorie_id' => $this->scSubvention->id,
        'libelle' => 'PCA Subventions',
        'montant' => -5000.00,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
    ]);

    $result = $this->service->provisionsExercice(2025);

    expect($result)->toHaveCount(2);
});

it('returns extournes as inverted provisions from N-1', function () {
    Provision::factory()->create([
        'exercice' => 2024,
        'type' => TypeTransaction::Depense,
        'sous_categorie_id' => $this->scLoyer->id,
        'libelle' => 'FNP Loyer',
        'montant' => 500.00,
        'saisi_par' => $this->user->id,
        'date' => '2025-08-31',
    ]);

    $extournes = $this->service->extournesExercice(2025);

    expect($extournes)->toHaveCount(1);
    // Original montantSigne = +500 (depense), extourne = -500
    expect($extournes->first()['montant_signe'])->toBe(-500.0);
});

it('computes total provisions for exercice', function () {
    Provision::factory()->create([
        'exercice' => 2025,
        'type' => TypeTransaction::Depense,
        'montant' => 500.00,
        'sous_categorie_id' => $this->scLoyer->id,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
    ]);
    Provision::factory()->create([
        'exercice' => 2025,
        'type' => TypeTransaction::Recette,
        'montant' => -3000.00,
        'sous_categorie_id' => $this->scSubvention->id,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
    ]);

    // FNP +500 + PCA -3000 = -2500
    expect($this->service->totalProvisions(2025))->toBe(-2500.0);
});

it('computes total extournes for exercice', function () {
    Provision::factory()->create([
        'exercice' => 2024,
        'type' => TypeTransaction::Depense,
        'montant' => 500.00,
        'sous_categorie_id' => $this->scLoyer->id,
        'saisi_par' => $this->user->id,
        'date' => '2025-08-31',
    ]);
    Provision::factory()->create([
        'exercice' => 2024,
        'type' => TypeTransaction::Recette,
        'montant' => -3000.00,
        'sous_categorie_id' => $this->scSubvention->id,
        'saisi_par' => $this->user->id,
        'date' => '2025-08-31',
    ]);

    // Inversion: -500 + 3000 = +2500
    expect($this->service->totalExtournes(2025))->toBe(2500.0);
});

it('returns empty collection when no provisions exist', function () {
    expect($this->service->provisionsExercice(2025))->toHaveCount(0);
    expect($this->service->extournesExercice(2025))->toHaveCount(0);
    expect($this->service->totalProvisions(2025))->toBe(0.0);
    expect($this->service->totalExtournes(2025))->toBe(0.0);
});
