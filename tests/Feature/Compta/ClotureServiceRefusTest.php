<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Exceptions\Compta\EtapeComptaRequiseException;
use App\Models\Exercice;
use App\Services\ExerciceService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

it('refuse de clôturer quand les préalables ne sont pas réunis', function (): void {
    $this->setupPartieDoubleContext();
    $this->compteBancaire->update(['solde_initial' => 2388.82]);
    $exercice = Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    expect(fn () => app(ExerciceService::class)->cloturer($exercice, $this->user))
        ->toThrow(EtapeComptaRequiseException::class);

    expect($exercice->fresh()->statut)->toBe(StatutExercice::Ouvert);
});

it('clôture normalement quand les préalables sont réunis', function (): void {
    $this->setupPartieDoubleContext();
    $exercice = Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    app(ExerciceService::class)->cloturer($exercice, $this->user);

    expect($exercice->fresh()->statut)->toBe(StatutExercice::Cloture);
});
