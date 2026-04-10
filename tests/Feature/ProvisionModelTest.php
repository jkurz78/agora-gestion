<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Provision;
use App\Models\SousCategorie;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->sc = SousCategorie::factory()->create();
});

it('computes montantSigne for depense (FNP)', function () {
    $provision = Provision::factory()->create([
        'type' => TypeTransaction::Depense,
        'montant' => 500.00,
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
        'date' => '2026-08-31',
    ]);

    expect($provision->montantSigne())->toBe(500.0);
});

it('computes montantSigne for recette (PCA negative)', function () {
    $provision = Provision::factory()->create([
        'type' => TypeTransaction::Recette,
        'montant' => -5000.00,
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
        'date' => '2026-08-31',
    ]);

    expect($provision->montantSigne())->toBe(-5000.0);
});

it('scopes by exercice', function () {
    Provision::factory()->create([
        'exercice' => 2025,
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
    ]);
    Provision::factory()->create([
        'exercice' => 2024,
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'date' => '2025-08-31',
    ]);

    expect(Provision::forExercice(2025)->count())->toBe(1);
});

it('has sousCategorie relation', function () {
    $provision = Provision::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
        'date' => '2026-08-31',
    ]);

    expect($provision->sousCategorie->id)->toBe($this->sc->id);
});

it('soft deletes', function () {
    $provision = Provision::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
        'date' => '2026-08-31',
    ]);

    $provision->delete();

    expect(Provision::count())->toBe(0);
    expect(Provision::withTrashed()->count())->toBe(1);
});
