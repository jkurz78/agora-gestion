<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Association;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $this->service = app(ExerciceService::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow(null);
    session()->forget('exercice_actif');
    TenantContext::clear();
});

describe('anneeForDate()', function () {
    it('returns the year for September date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2025-09-15')))->toBe(2025);
    });

    it('returns the year for December date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2025-12-01')))->toBe(2025);
    });

    it('returns previous year for January date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2026-01-15')))->toBe(2025);
    });

    it('returns previous year for August date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2026-08-31')))->toBe(2025);
    });
});

describe('exerciceAffiche()', function () {
    it('returns the Exercice model for current session year', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        session(['exercice_actif' => 2025]);
        expect($this->service->exerciceAffiche()->id)->toBe($exercice->id);
    });

    it('returns null when no exercice exists for the year', function () {
        session(['exercice_actif' => 2099]);
        expect($this->service->exerciceAffiche())->toBeNull();
    });
});

describe('assertOuvert()', function () {
    it('does not throw when exercice is open', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $this->service->assertOuvert(2025);
        expect(true)->toBeTrue();
    });

    it('throws ExerciceCloturedException when exercice is closed', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
        $this->service->assertOuvert(2025);
    })->throws(ExerciceCloturedException::class);

    it('does not throw when exercice does not exist in database', function () {
        $this->service->assertOuvert(2099);
        expect(true)->toBeTrue();
    });
});

describe('cloturer()', function () {
    it('closes the exercice and creates audit action', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $this->service->cloturer($exercice, $this->user);

        $exercice->refresh();
        expect($exercice->statut)->toBe(StatutExercice::Cloture)
            ->and($exercice->date_cloture)->not->toBeNull()
            ->and($exercice->cloture_par_id)->toBe($this->user->id)
            ->and(ExerciceAction::where('exercice_id', $exercice->id)
                ->where('action', TypeActionExercice::Cloture)->exists())->toBeTrue();
    });
});

describe('reouvrir()', function () {
    it('reopens a closed exercice with a comment', function () {
        $exercice = Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'date_cloture' => now(),
            'cloture_par_id' => $this->user->id,
        ]);
        $this->service->reouvrir($exercice, $this->user, 'Erreur de saisie détectée');

        $exercice->refresh();
        expect($exercice->statut)->toBe(StatutExercice::Ouvert)
            ->and($exercice->date_cloture)->toBeNull()
            ->and($exercice->cloture_par_id)->toBeNull()
            ->and(ExerciceAction::where('exercice_id', $exercice->id)
                ->where('action', TypeActionExercice::Reouverture)->exists())->toBeTrue();
    });
});

describe('creerExercice()', function () {
    it('creates a new exercice with creation action', function () {
        $exercice = $this->service->creerExercice(2025, $this->user);
        expect($exercice->annee)->toBe(2025)
            ->and($exercice->statut)->toBe(StatutExercice::Ouvert)
            ->and(ExerciceAction::where('exercice_id', $exercice->id)
                ->where('action', TypeActionExercice::Creation)->exists())->toBeTrue();
    });

    it('throws when exercice already exists', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $this->service->creerExercice(2025, $this->user);
    })->throws(QueryException::class);
});

describe('changerExerciceAffiche()', function () {
    it('updates session with exercice year', function () {
        $exercice = Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
        $this->service->changerExerciceAffiche($exercice);
        expect(session('exercice_actif'))->toBe(2024);
    });
});
