<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Services\ClotureCheckService;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

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
});

it('avertit quand des dotations ne sont pas générées', function (): void {
    Carbon::setTestNow('2027-10-15');

    $resultat = app(ClotureCheckService::class)->executer(2026);
    $item = collect($resultat->avertissements)->firstWhere('nom', 'Dotations aux amortissements');

    expect($item)->not->toBeNull()
        ->and($item->ok)->toBeFalse()
        ->and($item->message)->toContain('1');

    Carbon::setTestNow();
});

it('passe au vert une fois les dotations générées', function (): void {
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    $resultat = app(ClotureCheckService::class)->executer(2026);
    $item = collect($resultat->avertissements)->firstWhere('nom', 'Dotations aux amortissements');

    expect($item->ok)->toBeTrue();

    Carbon::setTestNow();
});
