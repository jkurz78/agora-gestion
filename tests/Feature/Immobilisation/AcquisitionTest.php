<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\CompteIncorrectException;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('crée la fiche et son écriture d’acquisition à crédit', function (): void {
    $tiers = Tiers::factory()->create();

    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
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

    expect($immo->numero)->toBe('IM00001')
        ->and($immo->quantite)->toBe(20);

    $tx = $immo->transaction;
    expect($tx->type)->toBe(TypeTransaction::Depense)
        ->and($tx->journal)->toBe(JournalComptable::Achat)
        ->and($tx->equilibree)->toBeTrue();

    $ligne2188 = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('2188')->id);
    expect($ligne2188->debit)->toEqual('3000.00');

    $ligne401 = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('401')->id);
    expect($ligne401->credit)->toEqual('3000.00')
        ->and((int) $ligne401->tiers_id)->toBe((int) $tiers->id);
});

it('refuse une mise en service antérieure à l’exercice de l’acquisition', function (): void {
    $tiers = Tiers::factory()->create();

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
        libelle: 'Matériel',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),      // exercice 2026
        dateMiseEnService: Carbon::parse('2026-06-01'), // exercice 2025
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(MiseEnServiceAnterieureException::class);
});

it('accepte une mise en service antérieure à l’achat dans le même exercice', function (): void {
    $tiers = Tiers::factory()->create();

    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
        libelle: 'Matériel livré puis facturé',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-10-15'),
        dateMiseEnService: Carbon::parse('2026-09-20'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    );

    expect($immo->date_mise_en_service->toDateString())->toBe('2026-09-20');
});

it('n’écrit aucune fiche si l’écriture échoue', function (): void {
    // Ni classe 2 ni classe 6 : refusé par pourDepenseACredit même avec le
    // drapeau autoriseImmobilisation (la classe 6 seule, elle, reste
    // toujours acceptée par ce garde-fou — voir EcritureGeneratorImmobilisationTest).
    //
    // Note : ->toThrow(Throwable::class) ne convient pas ici — Pest teste
    // class_exists() pour choisir entre comparaison de type et comparaison
    // de message, et Throwable est une interface (class_exists() renvoie
    // false), donc "Throwable" serait cherché comme sous-chaîne littérale du
    // message. On assert donc sur le type concret réellement levé.
    $tiers = Tiers::factory()->create();
    $compteInvalide = Compte::factory()->create(['numero_pcg' => '706', 'classe' => 7]);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
        libelle: 'Compte invalide',
        quantite: 1,
        compte: $compteInvalide,                           // classe 7 : refusé par le garde-fou classe
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(CompteIncorrectException::class);

    expect(Immobilisation::count())->toBe(0);
});
