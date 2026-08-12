<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Point 1 de l'audit robustesse — les mutations comptables du module
 * (fiche + dotations) sont désormais journalisées, sur le même patron que
 * FactureService::valider()/annuler() (voir AnnulerFactureLoggingTest) :
 * un Log::info('entite.action', [...ids...]) dispatché sans association_id
 * ni user_id — ces deux clés sont injectées automatiquement par
 * LogContext::boot() dans le middleware BootTenantConfig, pas dupliquées ici.
 */
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

test('acquerir() journalise immobilisation.acquise avec les identifiants utiles', function (): void {
    Log::spy();

    $immo = ($this->creerImmo)();

    Log::shouldHaveReceived('info')
        ->with('immobilisation.acquise', Mockery::on(function (array $context) use ($immo): bool {
            return (int) $context['immobilisation_id'] === (int) $immo->id
                && $context['numero'] === $immo->numero
                && (int) $context['transaction_id'] === (int) $immo->transaction_id;
        }))
        ->once();
});

test('modifier() journalise immobilisation.modifiee', function (): void {
    $immo = ($this->creerImmo)();

    Log::spy();

    app(ImmobilisationService::class)->modifier(
        immobilisation: $immo,
        libelle: 'Tenues renouvelées',
        quantite: 22,
        dureeMois: $immo->duree_mois,
        dateMiseEnService: $immo->date_mise_en_service,
        notes: null,
    );

    Log::shouldHaveReceived('info')
        ->with('immobilisation.modifiee', Mockery::on(function (array $context) use ($immo): bool {
            return (int) $context['immobilisation_id'] === (int) $immo->id
                && $context['numero'] === $immo->numero;
        }))
        ->once();
});

test('supprimer() journalise immobilisation.supprimee avec la transaction purgée', function (): void {
    $immo = ($this->creerImmo)();
    $transactionId = (int) $immo->transaction_id;

    Log::spy();

    app(ImmobilisationService::class)->supprimer($immo);

    Log::shouldHaveReceived('info')
        ->with('immobilisation.supprimee', Mockery::on(function (array $context) use ($immo, $transactionId): bool {
            return (int) $context['immobilisation_id'] === (int) $immo->id
                && $context['numero'] === $immo->numero
                && (int) $context['transaction_id'] === $transactionId;
        }))
        ->once();
});

test('generer() journalise immobilisation.dotation_generee pour chaque fiche dotée', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    Log::spy();

    app(DotationService::class)->generer(2026);

    Carbon::setTestNow();

    $dotation = $immo->dotations()->where('exercice', 2026)->firstOrFail();

    Log::shouldHaveReceived('info')
        ->with('immobilisation.dotation_generee', Mockery::on(function (array $context) use ($immo, $dotation): bool {
            return (int) $context['immobilisation_id'] === (int) $immo->id
                && $context['numero'] === $immo->numero
                && $context['exercice'] === 2026
                && (int) $context['transaction_id'] === (int) $dotation->transaction_id;
        }))
        ->once();
});

test('annuler() journalise immobilisation.dotation_annulee avec la transaction purgée', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    $transactionId = (int) $immo->dotations()->where('exercice', 2026)->firstOrFail()->transaction_id;

    Log::spy();

    app(DotationService::class)->annuler($immo->fresh(), 2026);

    Carbon::setTestNow();

    Log::shouldHaveReceived('info')
        ->with('immobilisation.dotation_annulee', Mockery::on(function (array $context) use ($immo, $transactionId): bool {
            return (int) $context['immobilisation_id'] === (int) $immo->id
                && $context['numero'] === $immo->numero
                && $context['exercice'] === 2026
                && (int) $context['transaction_id'] === $transactionId;
        }))
        ->once();
});

test('recalculer() journalise l’annulation puis la nouvelle génération', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    $immo->update(['duree_mois' => 30]);

    Log::spy();

    app(DotationService::class)->recalculer($immo->fresh(), 2026);

    Carbon::setTestNow();

    Log::shouldHaveReceived('info')
        ->with('immobilisation.dotation_annulee', Mockery::any())
        ->once();

    Log::shouldHaveReceived('info')
        ->with('immobilisation.dotation_generee', Mockery::any())
        ->once();
});
