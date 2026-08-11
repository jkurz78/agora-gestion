<?php

declare(strict_types=1);

use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

/**
 * Anomalie 1 (audit) — apercu() calcule le cumul antérieur comme la somme des
 * dotations d'exercices strictement antérieurs. Générer les exercices dans le
 * désordre (ou annuler un exercice ancien pendant qu'un exercice ultérieur est
 * déjà doté) fausse la charge affectée à chaque exercice. La correction impose
 * l'ordre chronologique plutôt que de recalculer silencieusement les exercices
 * ultérieurs déjà comptabilisés.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmo = function (string $montant = '3000.00', int $duree = 60, string $dateMiseEnService = '2026-09-12'): Immobilisation {
        return app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: '20 tenues d’escrime',
            quantite: 20,
            compte: Compte::ofNumero('2188'),
            compteAmortissement: Compte::ofNumero('28188'),
            montant: $montant,
            dateAchat: Carbon::parse($dateMiseEnService),
            dateMiseEnService: Carbon::parse($dateMiseEnService),
            dureeMois: $duree,
            modePaiement: null,
            compteTresorerie: null,
        );
    };
});

it('refuse de générer 2027 avant 2026 : l’exercice antérieur n’a pas été doté', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    expect(fn () => app(DotationService::class)->generer(2027))
        ->toThrow(DotationInterditeException::class, '2026');

    expect(ImmobilisationDotation::count())->toBe(0)
        ->and($immo->numero)->not->toBeEmpty();

    Carbon::setTestNow();
});

it('génère 2026 puis 2027 dans l’ordre : 600 € puis 600 €', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    app(DotationService::class)->generer(2026);
    app(DotationService::class)->generer(2027);

    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('600.00')
        ->and(ImmobilisationDotation::where('exercice', 2027)->firstOrFail()->montant)->toEqual('600.00');

    Carbon::setTestNow();
});

it('refuse d’annuler 2026 tant que 2027 est encore doté', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    app(DotationService::class)->generer(2026);
    app(DotationService::class)->generer(2027);

    expect(fn () => app(DotationService::class)->annuler($immo->fresh(), 2026))
        ->toThrow(DotationInterditeException::class, '2027');

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1)
        ->and(ImmobilisationDotation::where('exercice', 2027)->count())->toBe(1);

    Carbon::setTestNow();
});

it('annule 2027 puis 2026, dans l’ordre inverse de la génération', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    app(DotationService::class)->generer(2026);
    app(DotationService::class)->generer(2027);

    app(DotationService::class)->annuler($immo->fresh(), 2027);
    app(DotationService::class)->annuler($immo->fresh(), 2026);

    expect(ImmobilisationDotation::count())->toBe(0);

    Carbon::setTestNow();
});

it('un bien mis en service en 2027 ne bloque pas la génération de 2027, alors que 2026 n’a rien', function (): void {
    ($this->creerImmo)(dateMiseEnService: '2027-09-12');
    Carbon::setTestNow('2028-10-15');

    app(DotationService::class)->generer(2027);

    expect(ImmobilisationDotation::where('exercice', 2027)->count())->toBe(1)
        ->and(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(0);

    Carbon::setTestNow();
});
