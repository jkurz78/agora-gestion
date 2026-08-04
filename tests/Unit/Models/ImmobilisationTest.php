<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Transaction;
use App\Tenant\TenantContext;

it('crée une immobilisation tenant-scopée avec ses comptes', function (): void {
    $compte = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $compteAmort = Compte::factory()->create(['numero_pcg' => '28188', 'classe' => 2]);
    $tx = Transaction::factory()->create();

    $immo = Immobilisation::create([
        'numero' => 'IM00001',
        'libelle' => '20 tenues d’escrime',
        'quantite' => 20,
        'compte_id' => $compte->id,
        'compte_amortissement_id' => $compteAmort->id,
        'montant_acquisition' => '3000.00',
        'date_mise_en_service' => '2026-09-12',
        'duree_mois' => 60,
        'transaction_id' => $tx->id,
    ]);

    expect((int) $immo->association_id)->toBe((int) TenantContext::currentId())
        ->and($immo->quantite)->toBe(20)
        ->and($immo->duree_mois)->toBe(60)
        ->and($immo->compte->numero_pcg)->toBe('2188')
        ->and($immo->compteAmortissement->numero_pcg)->toBe('28188')
        ->and($immo->transactionsAcquisition())->toHaveCount(1);
});

it('affiche la durée en années quand elle est un multiple de 12', function (): void {
    $immo = Immobilisation::factory()->make(['duree_mois' => 60]);
    expect($immo->duree_label)->toBe('5 ans');

    $immo = Immobilisation::factory()->make(['duree_mois' => 30]);
    expect($immo->duree_label)->toBe('30 mois');

    $immo = Immobilisation::factory()->make(['duree_mois' => 12]);
    expect($immo->duree_label)->toBe('1 an');
});
