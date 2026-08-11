<?php

declare(strict_types=1);

use App\Exceptions\Immobilisation\DotationGenerationException;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

/**
 * Anomalie 4 (audit) — generer() bouclait sur les fiches en ouvrant une
 * transaction PAR FICHE. Une erreur au milieu du lot laissait un traitement
 * partiel, sans compte rendu de ce qui était passé. Au moment d'une clôture,
 * un lot à moitié passé est pire qu'un lot refusé : la correction rend tout
 * le lot atomique (une seule transaction) et nomme la fiche fautive.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmo = function (string $numeroCompte = '2188', string $numeroCompteAmortissement = '28188'): Immobilisation {
        return app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: '20 tenues d’escrime',
            quantite: 20,
            compte: Compte::ofNumero($numeroCompte),
            compteAmortissement: Compte::ofNumero($numeroCompteAmortissement),
            montant: '3000.00',
            dateAchat: Carbon::parse('2026-09-12'),
            dateMiseEnService: Carbon::parse('2026-09-12'),
            dureeMois: 60,
            modePaiement: null,
            compteTresorerie: null,
        );
    };
});

it('n’écrit aucune dotation si la deuxième fiche d’un lot de trois échoue', function (): void {
    ($this->creerImmo)();
    // Fiche du milieu : compte d'amortissement dédié ('28183', distinct de
    // celui des deux autres fiches), supprimé juste après pour provoquer un
    // échec technique réel et localisé (ModelNotFoundException) en plein lot
    // — EcritureGenerator::pourDotationAmortissement() fait un firstOrFail()
    // sur ce compte. La ligne reste en base (pas de violation de contrainte
    // de clé étrangère) : seul le scope SoftDeletes le rend introuvable.
    $immo2 = ($this->creerImmo)('2183', '28183');
    ($this->creerImmo)();

    expect($immo2->numero)->toBe('IM00002');

    Compte::ofNumero('28183')->delete();

    Carbon::setTestNow('2027-10-15');

    expect(fn () => app(DotationService::class)->generer(2026))
        ->toThrow(DotationGenerationException::class, 'IM00002');

    expect(ImmobilisationDotation::count())->toBe(0);

    Carbon::setTestNow();
});

it('génère normalement un lot de plusieurs fiches sans erreur', function (): void {
    ($this->creerImmo)();
    ($this->creerImmo)();
    ($this->creerImmo)();

    Carbon::setTestNow('2027-10-15');

    $nombre = app(DotationService::class)->generer(2026);

    expect($nombre)->toBe(3)
        ->and(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(3);

    Carbon::setTestNow();
});
