<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('comptes_bancaires table has est_systeme column', function () {
    expect(Schema::hasColumn('comptes_bancaires', 'est_systeme'))->toBeTrue();
});

it('system intermediary account exists', function () {
    $system = CompteBancaire::where('est_systeme', true)->first();

    expect($system)->not->toBeNull()
        ->and($system->nom)->toBe('Remises en banque')
        ->and($system->actif_recettes_depenses)->toBeFalse()
        ->and($system->est_systeme)->toBeTrue();
});

it('remises_bancaires table has all expected columns', function () {
    $columns = [
        'id', 'numero', 'date', 'mode_paiement',
        'compte_cible_id', 'virement_id', 'libelle',
        'saisi_par', 'created_at', 'updated_at', 'deleted_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('remises_bancaires', $column))->toBeTrue(
            "Column {$column} should exist on remises_bancaires"
        );
    }
});

it('transactions table has remise_id column', function () {
    expect(Schema::hasColumn('transactions', 'remise_id'))->toBeTrue();
});

it('transactions table has reglement_id column', function () {
    expect(Schema::hasColumn('transactions', 'reglement_id'))->toBeTrue();
});

it('reglements.remise_id FK nullOnDelete works', function () {
    // Create prerequisites
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test remise',
        'saisi_par' => $user->id,
    ]);

    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
    ]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cheque',
        'montant_prevu' => 50.00,
        'remise_id' => $remise->id,
    ]);

    // Force-delete the remise to trigger nullOnDelete
    $remise->forceDelete();

    $reglement->refresh();
    expect($reglement->remise_id)->toBeNull();
});

it('numero column on remises_bancaires is unique', function () {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    RemiseBancaire::create([
        'numero' => 42,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Remise A',
        'saisi_par' => $user->id,
    ]);

    RemiseBancaire::create([
        'numero' => 42,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Remise B',
        'saisi_par' => $user->id,
    ]);
})->throws(QueryException::class);
