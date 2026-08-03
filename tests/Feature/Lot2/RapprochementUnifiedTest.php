<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RapprochementBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates solde pointage without don/cotisation tables', function () {
    $compte = CompteBancaire::factory()->create(['solde_initial' => 1000.00]);
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'solde_ouverture' => 1000.00,
        'solde_fin' => 1200.00,
    ]);

    // Le solde de pointage se calcule sur les lignes 512X, pas sur montant_total :
    // il faut donc le compte de trésorerie du compte bancaire, et la ligne que
    // EcritureGenerator produirait en fonctionnement réel.
    $compte512X = Compte::factory()->numero('5121')->create([
        'classe' => 5,
        'compte_bancaire_id' => $compte->id,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
        'statut_reglement' => StatutReglement::Pointe->value,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte512X->id,
        'debit' => 200.00,
        'credit' => 0.0,
        'montant' => 200.00,
    ]);

    $service = app(RapprochementBancaireService::class);
    $solde = $service->calculerSoldePointage($rapprochement);

    expect($solde)->toBe(1200.00);
});

it('toggle only accepts depense, recette, virement types', function () {
    $compte = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
    ]);

    $service = app(RapprochementBancaireService::class);

    expect(fn () => $service->toggleTransaction($rapprochement, 'don', 1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->toggleTransaction($rapprochement, 'cotisation', 1))
        ->toThrow(InvalidArgumentException::class);
});

it('supprimer resets only transactions and virements', function () {
    $compte = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'rapprochement_id' => $rapprochement->id,
        'statut_reglement' => StatutReglement::Pointe->value,
    ]);

    $service = app(RapprochementBancaireService::class);
    $service->supprimer($rapprochement);

    expect(Transaction::find($tx->id)->rapprochement_id)->toBeNull();
    expect(Transaction::find($tx->id)->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect(RapprochementBancaire::find($rapprochement->id))->toBeNull();
});
