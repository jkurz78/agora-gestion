<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClotureCheckService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $this->service = app(ClotureCheckService::class);
});

afterEach(function () {
    TenantContext::clear();
});

describe('contrôles bloquants', function () {
    it('rapprochements en cours: passes when none exist', function () {
        $result = $this->service->executer(2025);
        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Rapprochements en cours');
        expect($bloquant->ok)->toBeTrue();
    });

    it('rapprochements en cours: fails when one exists in exercice period', function () {
        $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
        RapprochementBancaire::create([
            'association_id' => $this->association->id,
            'compte_id' => $compte->id,
            'date_fin' => '2025-11-30',
            'solde_ouverture' => 0,
            'solde_fin' => 100,
            'statut' => StatutRapprochement::EnCours,
            'saisi_par' => $this->user->id,
        ]);

        $result = $this->service->executer(2025);
        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Rapprochements en cours');
        expect($bloquant->ok)->toBeFalse();
    });

    it('lignes sans compte: passes when all have one', function () {
        $result = $this->service->executer(2025);
        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Lignes sans compte');
        expect($bloquant->ok)->toBeTrue();
    });

    it('lignes sans compte: fails when some lack it', function () {
        $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
        $transaction = Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $compte->id,
            'association_id' => $this->association->id,
        ]);
        $transaction->lignes()->create([
            'montant' => 50,
            'compte_id' => null,
        ]);

        $result = $this->service->executer(2025);
        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Lignes sans compte');
        expect($bloquant->ok)->toBeFalse();
    });
});

describe('contrôles avertissement', function () {
    it('transactions non pointées: passes when all are pointed', function () {
        $result = $this->service->executer(2025);
        $avert = collect($result->avertissements)->firstWhere('nom', 'Transactions non pointées');
        expect($avert->ok)->toBeTrue();
    });

    it('transactions non pointées: warns when some exist', function () {
        $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
        Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $compte->id,
            'association_id' => $this->association->id,
            'rapprochement_id' => null,
        ]);

        $result = $this->service->executer(2025);
        $avert = collect($result->avertissements)->firstWhere('nom', 'Transactions non pointées');
        expect($avert->ok)->toBeFalse();
    });

    it('budget absent: warns when no budget lines exist', function () {
        $result = $this->service->executer(2025);
        $avert = collect($result->avertissements)->firstWhere('nom', 'Budget absent');
        expect($avert->ok)->toBeFalse();
    });

    it('budget absent: passes when budget lines exist', function () {
        $compte = Compte::factory()->create(['association_id' => $this->association->id]);
        BudgetLine::create([
            'association_id' => $this->association->id,
            'compte_id' => $compte->id,
            'exercice' => 2025,
            'montant_prevu' => 100,
        ]);

        $result = $this->service->executer(2025);
        $avert = collect($result->avertissements)->firstWhere('nom', 'Budget absent');
        expect($avert->ok)->toBeTrue();
    });
});

describe('peutCloturer()', function () {
    it('returns true when all blocking checks pass', function () {
        $result = $this->service->executer(2025);
        expect($result->peutCloturer())->toBeTrue();
    });

    it('returns false when a blocking check fails', function () {
        $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
        RapprochementBancaire::create([
            'association_id' => $this->association->id,
            'compte_id' => $compte->id,
            'date_fin' => '2025-11-30',
            'solde_ouverture' => 0,
            'solde_fin' => 100,
            'statut' => StatutRapprochement::EnCours,
            'saisi_par' => $this->user->id,
        ]);

        $result = $this->service->executer(2025);
        expect($result->peutCloturer())->toBeFalse();
    });
});

it('returns soldes des comptes', function () {
    $result = $this->service->executer(2025);
    expect($result->soldesComptes)->toBeArray();
});
