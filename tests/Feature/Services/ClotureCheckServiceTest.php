<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClotureCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $this->service = app(ClotureCheckService::class);
});

describe('contrôles bloquants', function () {
    it('rapprochements en cours: passes when none exist', function () {
        $result = $this->service->executer(2025);
        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Rapprochements en cours');
        expect($bloquant->ok)->toBeTrue();
    });

    it('rapprochements en cours: fails when one exists in exercice period', function () {
        $compte = CompteBancaire::factory()->create();
        RapprochementBancaire::create([
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

    it('lignes sans sous-categorie: passes when all have one', function () {
        $result = $this->service->executer(2025);
        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Lignes sans sous-catégorie');
        expect($bloquant->ok)->toBeTrue();
    });

    it('lignes sans sous-categorie: fails when some lack it', function () {
        $compte = CompteBancaire::factory()->create();
        $transaction = Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $compte->id,
        ]);
        $transaction->lignes()->create([
            'montant' => 50,
            'sous_categorie_id' => null,
            'exercice' => 2025,
        ]);

        $result = $this->service->executer(2025);
        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Lignes sans sous-catégorie');
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
        $compte = CompteBancaire::factory()->create();
        Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $compte->id,
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
        $categorie = Categorie::factory()->create();
        $sc = SousCategorie::factory()->create(['categorie_id' => $categorie->id]);
        BudgetLine::create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 100]);

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
        $compte = CompteBancaire::factory()->create();
        RapprochementBancaire::create([
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
