<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Models\Association;
use App\Models\Devis;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create([
        'devis_validite_jours' => 30,
    ]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->service = app(DevisService::class);
});

afterEach(function () {
    TenantContext::clear();
});

describe('creer()', function () {
    it('retourne un Devis au statut brouillon avec date_emission = aujourd\'hui et date_validite = +30j', function () {
        Carbon::setTestNow('2026-05-01');

        $devis = $this->service->creer($this->tiers->id);

        expect($devis)->toBeInstanceOf(Devis::class)
            ->and($devis->statut)->toBe(StatutDevis::Brouillon)
            ->and($devis->date_emission->toDateString())->toBe('2026-05-01')
            ->and($devis->date_validite->toDateString())->toBe('2026-05-31')
            ->and($devis->montant_total)->toBe('0.00')
            ->and($devis->numero)->toBeNull()
            ->and($devis->tiers_id)->toBe((int) $this->tiers->id);

        Carbon::setTestNow();
    });

    it('utilise la date personnalisée passée en paramètre', function () {
        $date = Carbon::parse('2026-06-15');

        $devis = $this->service->creer($this->tiers->id, $date);

        expect($devis->date_emission->toDateString())->toBe('2026-06-15')
            ->and($devis->date_validite->toDateString())->toBe('2026-07-15');
    });

    it('utilise devis_validite_jours = 60 depuis l\'association', function () {
        $this->association->update(['devis_validite_jours' => 60]);
        // Reload fresh association in TenantContext
        TenantContext::boot($this->association->fresh());

        $date = Carbon::parse('2026-05-01');

        $devis = $this->service->creer($this->tiers->id, $date);

        expect($devis->date_validite->toDateString())->toBe('2026-06-30');
    });

    it('fixe l\'exercice selon anneeForDate(date_emission)', function () {
        $date = Carbon::parse('2026-11-15'); // Nov 2026 → exercice 2026 (mois_debut = 9 par défaut)
        $exerciceService = app(ExerciceService::class);
        $expectedExercice = $exerciceService->anneeForDate($date);

        $devis = $this->service->creer($this->tiers->id, $date);

        expect($devis->exercice)->toBe($expectedExercice);
    });

    it('fixe saisi_par_user_id à l\'utilisateur connecté', function () {
        $devis = $this->service->creer($this->tiers->id);

        expect((int) $devis->saisi_par_user_id)->toBe((int) $this->user->id);
    });

    it('persiste le devis en base de données', function () {
        $devis = $this->service->creer($this->tiers->id);

        $this->assertDatabaseHas('devis', [
            'id' => $devis->id,
            'statut' => StatutDevis::Brouillon->value,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 0,
            'numero' => null,
        ]);
    });
});
