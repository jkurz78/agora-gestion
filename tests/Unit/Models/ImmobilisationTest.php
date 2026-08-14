<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

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

/**
 * Audit — cumulAmortiCentimes() ignorait la relation `dotations` déjà chargée
 * et relançait `dotations()->sum('montant')` à chaque appel (une requête par
 * appel, N fois par fiche dans le livre). Les deux tests suivants verrouillent
 * le contrat : zéro requête quand la relation est chargée, une seule requête
 * — mémoïsée — quand elle ne l'est pas.
 */
it('cumulAmortiCentimes() utilise la relation dotations déjà chargée, sans requête supplémentaire', function (): void {
    $compte = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $compteAmort = Compte::factory()->create(['numero_pcg' => '28188', 'classe' => 2]);

    $immo = Immobilisation::create([
        'numero' => 'IM00001',
        'libelle' => '20 tenues d’escrime',
        'quantite' => 20,
        'compte_id' => $compte->id,
        'compte_amortissement_id' => $compteAmort->id,
        'montant_acquisition' => '3000.00',
        'date_mise_en_service' => '2026-09-12',
        'duree_mois' => 60,
        'transaction_id' => Transaction::factory()->create()->id,
    ]);

    ImmobilisationDotation::create([
        'immobilisation_id' => $immo->id,
        'exercice' => 2025,
        'montant' => '600.00',
        'transaction_id' => Transaction::factory()->create()->id,
    ]);

    $immo->load('dotations');

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($immo->cumulAmortiCentimes())->toBe(60000)
        ->and($immo->cumulAmortiCentimes())->toBe(60000);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});

it('cumulAmortiCentimes() retombe sur une requête mémoïsée quand la relation n’est pas chargée', function (): void {
    $compte = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $compteAmort = Compte::factory()->create(['numero_pcg' => '28188', 'classe' => 2]);

    $immoCree = Immobilisation::create([
        'numero' => 'IM00001',
        'libelle' => '20 tenues d’escrime',
        'quantite' => 20,
        'compte_id' => $compte->id,
        'compte_amortissement_id' => $compteAmort->id,
        'montant_acquisition' => '3000.00',
        'date_mise_en_service' => '2026-09-12',
        'duree_mois' => 60,
        'transaction_id' => Transaction::factory()->create()->id,
    ]);

    ImmobilisationDotation::create([
        'immobilisation_id' => $immoCree->id,
        'exercice' => 2025,
        'montant' => '600.00',
        'transaction_id' => Transaction::factory()->create()->id,
    ]);

    // Instance fraîche : la relation `dotations` n'est PAS chargée.
    $immo = Immobilisation::findOrFail($immoCree->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($immo->cumulAmortiCentimes())->toBe(60000)
        ->and($immo->cumulAmortiCentimes())->toBe(60000);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1, 'la deuxième lecture doit réutiliser la valeur mémoïsée, pas relancer la requête');
});
