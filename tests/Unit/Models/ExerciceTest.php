<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Exercice model', function () {
    it('casts statut to StatutExercice enum', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect($exercice->statut)->toBe(StatutExercice::Ouvert);
    });

    it('casts date_cloture to datetime', function () {
        $exercice = Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'date_cloture' => '2026-09-15 10:30:00',
        ]);
        expect($exercice->date_cloture)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('isCloture returns true when statut is Cloture', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
        expect($exercice->isCloture())->toBeTrue();
    });

    it('isCloture returns false when statut is Ouvert', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect($exercice->isCloture())->toBeFalse();
    });

    it('label returns formatted year range', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect($exercice->label())->toBe('2025-2026');
    });

    it('dateDebut returns September 1st of annee', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect($exercice->dateDebut()->format('Y-m-d'))->toBe('2025-09-01');
    });

    it('dateFin returns August 31st of annee+1', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect($exercice->dateFin()->format('Y-m-d'))->toBe('2026-08-31');
    });

    it('scopeOuvert filters open exercices', function () {
        Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect(Exercice::ouvert()->count())->toBe(1)
            ->and(Exercice::ouvert()->first()->annee)->toBe(2025);
    });

    it('scopeCloture filters closed exercices', function () {
        Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect(Exercice::cloture()->count())->toBe(1)
            ->and(Exercice::cloture()->first()->annee)->toBe(2024);
    });

    it('has many actions', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);
        expect($exercice->actions)->toHaveCount(1);
    });

    it('belongs to cloturePar user', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'cloture_par_id' => $user->id,
        ]);
        expect($exercice->cloturePar->id)->toBe($user->id);
    });
});

describe('ExerciceAction model', function () {
    it('casts action to TypeActionExercice enum', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);
        expect($action->action)->toBe(TypeActionExercice::Creation);
    });

    it('belongs to exercice', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);
        expect($action->exercice->id)->toBe($exercice->id);
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);
        expect($action->user->id)->toBe($user->id);
    });

    it('does not have updated_at column', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);
        expect($action->updated_at)->toBeNull();
    });
});
