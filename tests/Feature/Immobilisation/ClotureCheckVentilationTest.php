<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Services\ClotureCheckService;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

/**
 * Anomalie 2 (audit) — checkDotationsAmortissements() passe au vert dès que
 * les dotations de l'exercice sont générées et à jour, alors que son propre
 * message demande à l'utilisateur de les ventiler avant de clôturer : rien
 * ne vérifiait que c'était fait. Ce fichier couvre le contrôle séparé qui
 * comble ce manque.
 *
 * Décision assumée : une dotation non ventilée reste un avertissement,
 * jamais un bloquant — le trésorier reste maître de ses arbitrages
 * analytiques.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('signale une dotation générée mais non ventilée, en avertissement jamais bloquant', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    $resultat = app(ClotureCheckService::class)->executer(2026);
    $item = collect($resultat->avertissements)->firstWhere('nom', 'Ventilation des dotations');

    expect($item)->not->toBeNull()
        ->and($item->ok)->toBeFalse()
        ->and($item->message)->toContain('1')
        ->and(collect($resultat->bloquants)->pluck('nom'))->not->toContain('Ventilation des dotations');
});

it('passe au vert une fois la dotation ventilée', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    $dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
    $ligne = TransactionLigne::where('transaction_id', (int) $dotation->transaction_id)
        ->whereHas('compte', fn ($q) => $q->where('numero_pcg', '6811'))
        ->firstOrFail();

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => Operation::factory()->create()->id,
        'seance' => null,
        'montant' => $ligne->montant,
        'notes' => null,
    ]);

    $resultat = app(ClotureCheckService::class)->executer(2026);
    $item = collect($resultat->avertissements)->firstWhere('nom', 'Ventilation des dotations');

    expect($item)->not->toBeNull()
        ->and($item->ok)->toBeTrue();
});

it('reste au vert avec un message adapté quand il n’y a aucune immobilisation', function (): void {
    $resultat = app(ClotureCheckService::class)->executer(2026);
    $item = collect($resultat->avertissements)->firstWhere('nom', 'Ventilation des dotations');

    expect($item)->not->toBeNull()
        ->and($item->ok)->toBeTrue()
        ->and($item->message)->not->toBe('Toutes les dotations de l’exercice sont ventilées');
});
