<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\Rapports\LivreImmobilisationsBuilder;
use Carbon\Carbon;

/**
 * Livre des immobilisations — état du registre à la clôture d'un exercice,
 * exporté en PDF et en Excel comme les autres rapports.
 *
 * La VNC retenue est la valeur THÉORIQUE au dernier jour de l'exercice, celle
 * que prévoit le plan d'amortissement — décision produit du 13/08/2026. C'est
 * le chiffre dont on a besoin pour préparer le bilan, y compris avant d'avoir
 * généré les dotations : les attendre rendrait le rapport inutilisable au
 * moment précis où l'on s'en sert. Il peut donc diverger du grand livre tant
 * que les dotations ne sont pas passées, ce qui est assumé.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->acquerir = function (string $montant, string $dateAchat, int $dureeMois = 96) {
        return app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: 'Deux gros 4x4',
            quantite: 2,
            compte: Compte::ofNumero('2154'),
            compteAmortissement: Compte::ofNumero('28154'),
            montant: $montant,
            dateAchat: Carbon::parse($dateAchat),
            dateMiseEnService: Carbon::parse($dateAchat),
            dureeMois: $dureeMois,
            modePaiement: null,
            compteTresorerie: null,
        );
    };
});

it('calcule la VNC théorique au dernier jour de l’exercice', function (): void {
    // 30 000 € sur 96 mois = 312,50 €/mois. Mise en service au 15/10/2026,
    // exercice 2026 clos au 31/08/2027 : 11 mois courus.
    ($this->acquerir)('30000.00', '2026-10-15');

    $livre = app(LivreImmobilisationsBuilder::class)->pourExercice(2026);

    expect($livre['lignes'])->toHaveCount(1);

    $ligne = $livre['lignes'][0];

    expect($ligne['montant_acquisition_centimes'])->toBe(3000000)
        ->and($ligne['cumul_centimes'])->toBe(343750)
        ->and($ligne['dotation_centimes'])->toBe(343750)
        ->and($ligne['vnc_centimes'])->toBe(3000000 - 343750);
});

it('isole la dotation du seul exercice demandé', function (): void {
    ($this->acquerir)('30000.00', '2026-10-15');

    // Exercice suivant : 23 mois courus au 31/08/2028, la dotation de l'année
    // est l'écart entre les deux cumuls, soit 12 mois pleins.
    $livre = app(LivreImmobilisationsBuilder::class)->pourExercice(2027);
    $ligne = $livre['lignes'][0];

    expect($ligne['cumul_centimes'])->toBe(718750)
        ->and($ligne['dotation_centimes'])->toBe(718750 - 343750);
});

it('n’inclut pas une immobilisation acquise après la clôture de l’exercice', function (): void {
    ($this->acquerir)('30000.00', '2026-10-15');
    ($this->acquerir)('5000.00', '2027-11-20');

    $livre = app(LivreImmobilisationsBuilder::class)->pourExercice(2026);

    expect($livre['lignes'])->toHaveCount(1)
        ->and($livre['lignes'][0]['montant_acquisition_centimes'])->toBe(3000000);
});

it('totalise le registre', function (): void {
    ($this->acquerir)('30000.00', '2026-10-15');
    ($this->acquerir)('12000.00', '2026-10-15', 48);

    $livre = app(LivreImmobilisationsBuilder::class)->pourExercice(2026);

    // 12 000 € sur 48 mois = 250 €/mois × 11 = 2 750,00 €.
    expect($livre['totaux']['brut'])->toBe(3000000 + 1200000)
        ->and($livre['totaux']['cumul'])->toBe(343750 + 275000)
        ->and($livre['totaux']['vnc'])->toBe((3000000 + 1200000) - (343750 + 275000));
});
