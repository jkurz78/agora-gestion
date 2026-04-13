<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('facture creation with all fields and correct casts', function (): void {
    $tiers = Tiers::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'numero' => 'FA-2026-001',
        'date' => '2026-03-31',
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'conditions_reglement' => 'À réception',
        'mentions_legales' => 'Association loi 1901',
        'montant_total' => 150.50,
        'notes' => 'Facture test',
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $facture->refresh();

    expect($facture->numero)->toBe('FA-2026-001')
        ->and($facture->date)->toBeInstanceOf(Carbon::class)
        ->and($facture->date->toDateString())->toBe('2026-03-31')
        ->and($facture->statut)->toBe(StatutFacture::Brouillon)
        ->and($facture->tiers_id)->toBe($tiers->id)
        ->and($facture->compte_bancaire_id)->toBe($compte->id)
        ->and($facture->conditions_reglement)->toBe('À réception')
        ->and($facture->mentions_legales)->toBe('Association loi 1901')
        ->and($facture->montant_total)->toBe('150.50')
        ->and($facture->notes)->toBe('Facture test')
        ->and($facture->saisi_par)->toBe($user->id)
        ->and($facture->exercice)->toBe(2025);
});

test('facture tiers relation', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    expect($facture->tiers->id)->toBe($tiers->id);
});

test('facture compteBancaire relation', function (): void {
    $tiers = Tiers::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    expect($facture->compteBancaire->id)->toBe($compte->id);
});

test('facture saisiPar relation', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    expect($facture->saisiPar->id)->toBe($user->id);
});

test('facture lignes relation returns ordered lines', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 100,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => 'montant',
        'libelle' => 'Ligne 2',
        'montant' => 60.00,
        'ordre' => 2,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => 'montant',
        'libelle' => 'Ligne 1',
        'montant' => 40.00,
        'ordre' => 1,
    ]);

    $lignes = $facture->lignes;

    expect($lignes)->toHaveCount(2)
        ->and($lignes->first()->libelle)->toBe('Ligne 1')
        ->and($lignes->last()->libelle)->toBe('Ligne 2');
});

test('facture transactions relation via pivot', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 100,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction1 = Transaction::factory()->create();
    $transaction2 = Transaction::factory()->create();

    $facture->transactions()->attach([$transaction1->id, $transaction2->id]);

    expect($facture->transactions)->toHaveCount(2);
});

test('montantRegle returns 0 when transactions are on système account', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compteSysteme = CompteBancaire::factory()->create(['est_systeme' => true]);

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'tiers_id' => $tiers->id,
        'montant_total' => 200,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction = Transaction::factory()->create([
        'montant_total' => 200.00,
        'compte_id' => $compteSysteme->id,
    ]);

    $facture->transactions()->attach($transaction->id);

    expect($facture->montantRegle())->toBe(0.0);
});

test('montantRegle returns sum for transactions on non-système accounts', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
    $compteSysteme = CompteBancaire::factory()->create(['est_systeme' => true]);

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'tiers_id' => $tiers->id,
        'montant_total' => 300,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    // Virement statut_reglement=recu → réglé
    $txVirement = Transaction::factory()->create([
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'compte_id' => $compteReel->id,
        'statut_reglement' => 'recu',
    ]);
    // Chèque statut_reglement=recu → réglé
    $txCheque = Transaction::factory()->create([
        'montant_total' => 100.00,
        'mode_paiement' => 'cheque',
        'compte_id' => $compteReel->id,
        'statut_reglement' => 'recu',
    ]);
    // Chèque statut_reglement=en_attente → non réglé
    $txAttente = Transaction::factory()->create([
        'montant_total' => 100.00,
        'mode_paiement' => 'cheque',
        'compte_id' => $compteSysteme->id,
        'statut_reglement' => 'en_attente',
    ]);

    $facture->transactions()->attach([$txVirement->id, $txCheque->id, $txAttente->id]);

    // 100 + 100 (statut recu) = 200, en_attente = non réglé
    expect($facture->montantRegle())->toBe(200.0);
});

test('montantRegle considers remise transactions on système account as paid', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compteSysteme = CompteBancaire::factory()->create(['est_systeme' => true, 'nom' => 'Remises en banque']);
    $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);

    $remise = RemiseBancaire::create([
        'numero' => 99,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compteReel->id,
        'libelle' => 'Test remise',
        'saisi_par' => $user->id,
    ]);

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'numero' => 'F-2025-0099',
        'tiers_id' => $tiers->id,
        'montant_total' => 150,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    // Transaction statut_reglement=recu (dans une remise) → réglée
    $txRemise = Transaction::factory()->create([
        'montant_total' => 100.00,
        'compte_id' => $compteSysteme->id,
        'remise_id' => $remise->id,
        'statut_reglement' => 'recu',
    ]);
    // Transaction statut_reglement=en_attente → non réglée
    $txAttente = Transaction::factory()->create([
        'montant_total' => 50.00,
        'compte_id' => $compteSysteme->id,
        'mode_paiement' => 'cheque',
        'remise_id' => null,
        'statut_reglement' => 'en_attente',
    ]);

    $facture->transactions()->attach([$txRemise->id, $txAttente->id]);

    expect($facture->montantRegle())->toBe(100.0);
});

test('isAcquittee returns false when not fully paid', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test remise',
        'saisi_par' => $user->id,
    ]);

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'tiers_id' => $tiers->id,
        'montant_total' => 200,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction = Transaction::factory()->create([
        'montant_total' => 100.00,
        'remise_id' => $remise->id,
    ]);

    $facture->transactions()->attach($transaction->id);

    expect($facture->isAcquittee())->toBeFalse();
});

test('isAcquittee returns true when fully paid', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'tiers_id' => $tiers->id,
        'montant_total' => 200,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction = Transaction::factory()->create([
        'montant_total' => 200.00,
        'compte_id' => $compte->id,
        'statut_reglement' => 'recu',
    ]);

    $facture->transactions()->attach($transaction->id);

    expect($facture->isAcquittee())->toBeTrue();
});

test('isAcquittee returns false when statut is brouillon even if fully paid', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $compte->id,
        'libelle' => 'Test remise',
        'saisi_par' => $user->id,
    ]);

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 200,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction = Transaction::factory()->create([
        'montant_total' => 200.00,
        'remise_id' => $remise->id,
    ]);

    $facture->transactions()->attach($transaction->id);

    expect($facture->isAcquittee())->toBeFalse();
});

test('isLockedByFacture returns true when transaction linked to validated facture', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'tiers_id' => $tiers->id,
        'montant_total' => 100,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction = Transaction::factory()->create();
    $facture->transactions()->attach($transaction->id);

    expect($transaction->isLockedByFacture())->toBeTrue();
});

test('isLockedByFacture returns false when transaction linked to brouillon facture', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 100,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction = Transaction::factory()->create();
    $facture->transactions()->attach($transaction->id);

    expect($transaction->isLockedByFacture())->toBeFalse();
});

test('isLockedByFacture returns false when transaction linked to annulee facture', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'annulee',
        'tiers_id' => $tiers->id,
        'montant_total' => 100,
        'saisi_par' => $user->id,
        'exercice' => 2025,
        'date_annulation' => now()->toDateString(),
    ]);

    $transaction = Transaction::factory()->create();
    $facture->transactions()->attach($transaction->id);

    expect($transaction->isLockedByFacture())->toBeFalse();
});

test('FactureLigne creation with correct casts', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 75.00,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => 'montant',
        'libelle' => 'Prestation conseil',
        'montant' => 75.00,
        'ordre' => 1,
    ]);

    $ligne->refresh();

    expect($ligne->facture_id)->toBe($facture->id)
        ->and($ligne->type)->toBe(TypeLigneFacture::Montant)
        ->and($ligne->libelle)->toBe('Prestation conseil')
        ->and($ligne->montant)->toBe('75.00')
        ->and($ligne->ordre)->toBe(1);
});

test('FactureLigne facture relation', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 50,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => 'texte',
        'libelle' => 'Note libre',
        'ordre' => 1,
    ]);

    expect($ligne->facture->id)->toBe($facture->id);
});

test('FactureLigne transactionLigne relation', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create();
    $transactionLigne = $transaction->lignes->first();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 50,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'transaction_ligne_id' => $transactionLigne->id,
        'type' => 'montant',
        'libelle' => 'Ligne liée',
        'montant' => 50.00,
        'ordre' => 1,
    ]);

    expect($ligne->transactionLigne->id)->toBe($transactionLigne->id);
});

it('compte une transaction avec statut_reglement=recu', function (): void {
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 120.00,
        'saisi_par' => $user->id,
        'exercice' => now()->month >= 9 ? now()->year : now()->year - 1,
    ]);

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'montant_total' => 120.00,
        'statut_reglement' => 'recu',
    ]);

    $facture->transactions()->attach($transaction->id);

    expect($facture->montantRegle())->toBe(120.00);
    expect($facture->isAcquittee())->toBeTrue();
});

it('ne compte pas une transaction avec statut_reglement=en_attente', function (): void {
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 120.00,
        'saisi_par' => $user->id,
        'exercice' => now()->month >= 9 ? now()->year : now()->year - 1,
    ]);

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'montant_total' => 120.00,
        'statut_reglement' => 'en_attente',
    ]);

    $facture->transactions()->attach($transaction->id);

    expect($facture->montantRegle())->toBe(0.0);
    expect($facture->isAcquittee())->toBeFalse();
});

test('Transaction factures relation via pivot', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture1 = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 100,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $facture2 = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'tiers_id' => $tiers->id,
        'montant_total' => 200,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $transaction = Transaction::factory()->create();
    $transaction->factures()->attach([$facture1->id, $facture2->id]);

    expect($transaction->factures)->toHaveCount(2);
});
