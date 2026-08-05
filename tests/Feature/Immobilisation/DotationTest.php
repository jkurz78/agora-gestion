<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmo = function (string $montant = '3000.00', int $duree = 60): Immobilisation {
        return app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: '20 tenues d’escrime',
            quantite: 20,
            compte: Compte::ofNumero('2188'),
            compteAmortissement: Compte::ofNumero('28188'),
            montant: $montant,
            dateAchat: Carbon::parse('2026-09-12'),
            dateMiseEnService: Carbon::parse('2026-09-12'),
            dureeMois: $duree,
            modePaiement: null,
            compteTresorerie: null,
        );
    };
});

it('génère une écriture 6811 / 28188 datée du dernier jour de l’exercice', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15'); // on clôture en octobre, après la fin de l’exercice

    app(DotationService::class)->generer(2026);

    $dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
    expect($dotation->montant)->toEqual('600.00');

    $tx = $dotation->transaction;
    expect($tx->date->toDateString())->toBe('2027-08-31')
        ->and($tx->type)->toBe(TypeTransaction::Depense)
        ->and($tx->journal)->toBe(JournalComptable::Od)
        ->and($tx->equilibree)->toBeTrue()
        ->and($tx->libelle)->toContain('IM00001');

    $debit = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('6811')->id);
    $credit = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('28188')->id);
    expect($debit->debit)->toEqual('600.00')
        ->and($credit->credit)->toEqual('600.00');

    Carbon::setTestNow();
});

it('ignore la date du jour pour dater l’écriture', function (): void {
    ($this->creerImmo)();

    Carbon::setTestNow('2029-01-05'); // deux ans plus tard
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->transaction->date->toDateString())
        ->toBe('2027-08-31');
});

it('est idempotent : un rejeu ne crée pas de doublon', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    app(DotationService::class)->generer(2026);
    app(DotationService::class)->generer(2026);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    Carbon::setTestNow();
});

it('refuse de générer sur un exercice non terminé', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-03-01'); // l’exercice 2026 se termine le 31/08/2027

    expect(fn () => app(DotationService::class)->generer(2026))
        ->toThrow(DotationInterditeException::class);

    Carbon::setTestNow();
});

it('refuse de générer sur un exercice clôturé', function (): void {
    ($this->creerImmo)();
    // Il n'existe pas d'ExerciceFactory : on crée l'exercice directement.
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);
    Carbon::setTestNow('2027-10-15');

    expect(fn () => app(DotationService::class)->generer(2026))
        ->toThrow(DotationInterditeException::class);

    Carbon::setTestNow();
});

it('détecte l’écart après modification de la fiche et recalcule', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    app(DotationService::class)->generer(2026);
    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('600.00');

    // La durée passe de 60 à 30 mois : le cumul théorique fin 2026 double.
    $immo->update(['duree_mois' => 30]);

    $apercu = app(DotationService::class)->apercu(2026);
    $ligne = $apercu->first();
    expect($ligne->montantComptabiliseCentimes)->toBe(60000)
        ->and($ligne->montantRecalculeCentimes)->toBe(120000)
        ->and($ligne->enEcart())->toBeTrue();

    app(DotationService::class)->recalculer($immo->fresh(), 2026);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1)
        ->and(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('1200.00');

    Carbon::setTestNow();
});

it('ne dote pas un bien pas encore en service', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel commandé',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2028-03-01'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(0);

    Carbon::setTestNow();
});
