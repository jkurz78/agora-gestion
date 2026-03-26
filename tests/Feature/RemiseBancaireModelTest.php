<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use Illuminate\Support\Carbon;

test('creating RemiseBancaire with correct casts', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2026-03-26',
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Remise chèques mars',
        'saisi_par' => $user->id,
    ]);

    $remise->refresh();

    expect($remise->numero)->toBe(1)
        ->and($remise->date)->toBeInstanceOf(Carbon::class)
        ->and($remise->date->toDateString())->toBe('2026-03-26')
        ->and($remise->mode_paiement)->toBe(ModePaiement::Cheque)
        ->and($remise->compte_cible_id)->toBe($compte->id)
        ->and($remise->saisi_par)->toBe($user->id);
});

test('remise compteCible relation', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    expect($remise->compteCible->id)->toBe($compte->id);
});

test('remise virement relation', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();
    $virement = VirementInterne::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'virement_id' => $virement->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    expect($remise->virement->id)->toBe($virement->id);
});

test('remise saisiPar relation', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    expect($remise->saisiPar->id)->toBe($user->id);
});

test('isVerrouillee returns false when no virement', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    expect($remise->isVerrouillee())->toBeFalse();
});

test('referencePrefix returns RBC for cheque', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    expect($remise->referencePrefix())->toBe('RBC');
});

test('referencePrefix returns RBE for especes', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'especes',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    expect($remise->referencePrefix())->toBe('RBE');
});

test('montantTotal sums linked reglements montant_prevu', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    $operation = Operation::factory()->create();
    $tiers1 = Tiers::factory()->create();
    $tiers2 = Tiers::factory()->create();
    $p1 = Participant::create(['tiers_id' => $tiers1->id, 'operation_id' => $operation->id, 'date_inscription' => now()->toDateString()]);
    $p2 = Participant::create(['tiers_id' => $tiers2->id, 'operation_id' => $operation->id, 'date_inscription' => now()->toDateString()]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1]);

    Reglement::create([
        'participant_id' => $p1->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cheque',
        'montant_prevu' => 30.00,
        'remise_id' => $remise->id,
    ]);

    Reglement::create([
        'participant_id' => $p2->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cheque',
        'montant_prevu' => 20.50,
        'remise_id' => $remise->id,
    ]);

    expect($remise->montantTotal())->toBe(50.50);
});

test('CompteBancaire est_systeme defaults to false', function (): void {
    $compte = CompteBancaire::factory()->create();
    expect($compte->est_systeme)->toBeFalse();
});

test('CompteBancaire est_systeme can be set to true', function (): void {
    $compte = CompteBancaire::factory()->create(['est_systeme' => true]);
    $compte->refresh();
    expect($compte->est_systeme)->toBeTrue();
});

test('Transaction has remise relation', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    $transaction = Transaction::factory()->create([
        'remise_id' => $remise->id,
    ]);

    expect($transaction->remise->id)->toBe($remise->id);
});

test('Transaction has reglement relation', function (): void {
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cheque',
        'montant_prevu' => 50.00,
    ]);

    $transaction = Transaction::factory()->create([
        'reglement_id' => $reglement->id,
    ]);

    expect($transaction->reglement->id)->toBe($reglement->id);
});

test('Reglement has remise relation', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cheque',
        'montant_prevu' => 50.00,
        'remise_id' => $remise->id,
    ]);

    expect($reglement->remise->id)->toBe($remise->id);
});

test('RemiseBancaire has reglements relation', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cheque',
        'montant_prevu' => 50.00,
        'remise_id' => $remise->id,
    ]);

    expect($remise->reglements)->toHaveCount(1);
});

test('RemiseBancaire has transactions relation', function (): void {
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test',
        'saisi_par' => $user->id,
    ]);

    Transaction::factory()->create(['remise_id' => $remise->id]);
    Transaction::factory()->create(['remise_id' => $remise->id]);

    expect($remise->transactions)->toHaveCount(2);
});
