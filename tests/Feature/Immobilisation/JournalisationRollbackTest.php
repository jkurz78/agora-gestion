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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Contre-audit P2-03 — les événements de dotation étaient émis à l'intérieur
 * de la transaction qui porte l'écriture. generer() enveloppant tout le lot
 * dans une seule transaction (Anomalie 4), l'échec de la deuxième fiche
 * rollbackait les données de la première mais laissait sa ligne de journal :
 * le journal annonçait une dotation qui n'existe pas. Même divergence dans
 * recalculer(), où l'annulation pouvait être journalisée avant l'échec de la
 * régénération.
 *
 * Un journal qui annonce des écritures absentes de la base est pire qu'un
 * journal muet : c'est sur lui qu'on s'appuie pour reconstituer ce qui s'est
 * réellement passé un jour de clôture.
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

afterEach(function (): void {
    Carbon::setTestNow();
});

it('n’émet aucun log de génération quand la deuxième fiche du lot échoue', function (): void {
    ($this->creerImmo)();
    // Même injection d'échec que DotationGenerationAtomiqueTest : compte
    // d'amortissement dédié à la fiche du milieu, supprimé pour faire échouer
    // le firstOrFail() de EcritureGenerator::pourDotationAmortissement().
    ($this->creerImmo)('2183', '28183');
    ($this->creerImmo)();

    Compte::ofNumero('28183')->delete();
    Carbon::setTestNow('2027-10-15');

    Log::spy();

    expect(fn () => app(DotationService::class)->generer(2026))
        ->toThrow(DotationGenerationException::class);

    expect(ImmobilisationDotation::count())->toBe(0);

    Log::shouldNotHaveReceived('info', ['immobilisation.dotation_generee', Mockery::any()]);
});

it('émet le log de génération une fois le lot committé', function (): void {
    ($this->creerImmo)();
    ($this->creerImmo)();

    Carbon::setTestNow('2027-10-15');

    Log::spy();

    expect(app(DotationService::class)->generer(2026))->toBe(2);

    Log::shouldHaveReceived('info')
        ->with('immobilisation.dotation_generee', Mockery::any())
        ->twice();
});

it('n’émet aucun log d’annulation quand la régénération échoue', function (): void {
    $immo = ($this->creerImmo)('2183', '28183');

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    // La régénération échouera : le compte d'amortissement disparaît entre
    // l'annulation et la recomptabilisation.
    Compte::ofNumero('28183')->delete();

    Log::spy();

    expect(fn () => app(DotationService::class)->recalculer($immo, 2026))
        ->toThrow(ModelNotFoundException::class);

    // La dotation d'origine est toujours là : le rollback a défait l'annulation.
    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    Log::shouldNotHaveReceived('info', ['immobilisation.dotation_annulee', Mockery::any()]);
});
