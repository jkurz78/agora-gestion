<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

/**
 * Contre-audit P3-01 — le règlement immédiat n'était généré que si le mode ET
 * le compte de trésorerie étaient tous deux renseignés. Un couple incomplet ne
 * produisait aucune erreur : un mode sans compte retombait silencieusement en
 * acquisition à crédit, un compte sans mode voyait son compte ignoré.
 *
 * L'écran ne peut pas produire ce couple (ImmobilisationIndex résout le compte
 * via CompteTresorerieResolver et refuse si null), mais le service est la
 * frontière métier réutilisable — commande artisan, import, job à venir. Une
 * acquisition réglée doit produire un règlement ou échouer, jamais se
 * transformer en dette 401 sans que l'appelant le sache.
 */
beforeEach(function (): void {
    SystemeSeeder::seed();
    ImmobilisationComptesSeeder::seed();
});

$acquerir = function (?ModePaiement $mode, ?Compte $compteTresorerie): Immobilisation {
    return app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: $mode,
        compteTresorerie: $compteTresorerie,
    );
};

it('refuse un mode de paiement sans compte de trésorerie', function () use ($acquerir): void {
    expect(fn (): Immobilisation => $acquerir(ModePaiement::Virement, null))
        ->toThrow(
            InvalidArgumentException::class,
            'Un règlement immédiat exige à la fois un mode de paiement et un compte de trésorerie.'
        );

    expect(Immobilisation::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

it('refuse un compte de trésorerie sans mode de paiement', function () use ($acquerir): void {
    expect(fn (): Immobilisation => $acquerir(null, Compte::ofNumeroSysteme('530')))
        ->toThrow(
            InvalidArgumentException::class,
            'Un règlement immédiat exige à la fois un mode de paiement et un compte de trésorerie.'
        );

    expect(Immobilisation::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

it('accepte toujours le couple vide — acquisition à crédit', function () use ($acquerir): void {
    $immo = $acquerir(null, null);

    expect($immo->numero)->toBe('IM00001')
        ->and(Transaction::count())->toBe(1);
});
