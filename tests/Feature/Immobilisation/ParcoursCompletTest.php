<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\Rapports\CompteResultatBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // SystemeSeeder (pas seulement le 401 manuel des autres tests du module) :
    // l'à-nouveau a besoin des comptes 120/129 (résultat de l'exercice) dès
    // que l'exercice dégage un résultat non nul — ce qui est le cas ici,
    // la dotation constatant une charge en 6811.
    SystemeSeeder::seed();
    ImmobilisationComptesSeeder::seed();
});

it('exclut l’acquisition du compte de résultat et y intègre la dotation', function (): void {
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

    // La dotation est datée du dernier jour de l'exercice par construction
    // (DotationService::finExercice). La colonne `transactions.date` est un
    // vrai DATE en MySQL (prod), qui tronque toute heure à l'écriture ; en
    // test, SQLite ne fait pas cette troncature et Eloquent y stocke
    // "AAAA-08-31 00:00:00". CompteResultatBuilder::exerciceDates() borne sa
    // requête avec whereBetween(..., 'AAAA-08-31') (chaîne sans heure) : en
    // comparaison lexicographique SQLite, "...-08-31 00:00:00" est exclu car
    // "postérieur" à la borne "...-08-31" (cf. feedback_sqlite_date_boundary.md).
    // On normalise ici la valeur stockée pour reproduire fidèlement le
    // comportement MySQL réel, sans toucher à CompteResultatBuilder.
    DB::statement('UPDATE transactions SET date = date(date)');

    $resultat = app(CompteResultatBuilder::class)->compteDeResultat(2026);

    // CompteResultatBuilder::compteDeResultat() ne renvoie ni numero_pcg ni
    // libellé de compte portant le numéro — chaque compte n'y apparaît que
    // par compte_id/compte_nom (intitulé). On compare donc par identifiant
    // de compte plutôt que par recherche textuelle du numéro PCG.
    $compte2188Id = (int) Compte::ofNumero('2188')->id;
    $compte6811Id = (int) Compte::ofNumero('6811')->id;

    $tousLesComptes = collect($resultat['charges'])
        ->flatMap(fn (array $famille): array => $famille['comptes'])
        ->concat(
            collect($resultat['produits'])->flatMap(fn (array $famille): array => $famille['comptes'])
        );

    // L'acquisition (classe 2) n'apparaît pas : le compte de résultat ne lit
    // que les classes 6 et 7, la classe 2 en est exclue mécaniquement.
    expect($tousLesComptes->pluck('compte_id'))->not->toContain($compte2188Id);

    // La dotation (classe 6) y figure, pour 600,00 €.
    $ligneDotation = $tousLesComptes->firstWhere('compte_id', $compte6811Id);

    expect($ligneDotation)->not->toBeNull()
        ->and($ligneDotation['montant_n'])->toEqual(600.0);
});

it('reporte les soldes 21X et 281X à l’à-nouveau', function (): void {
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

    // ANouveauPreview est un readonly avec une propriété publique `lignes`,
    // chaque ligne étant un array portant la clé `numero_pcg`.
    $preview = app(ANouveauPreviewBuilder::class)->build(2026);
    $numeros = collect($preview->lignes)->pluck('numero_pcg');

    expect($numeros)->toContain('2188')
        ->and($numeros)->toContain('28188');
});
