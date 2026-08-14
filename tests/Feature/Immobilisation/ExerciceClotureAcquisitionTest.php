<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

/**
 * Audit — ImmobilisationService::acquerir() ne vérifiait jamais que
 * l'exercice de la date d'achat était ouvert, et la validation Livewire
 * acceptait n'importe quelle date : on pouvait écrire dans un exercice
 * clôturé, ce que tout le reste de l'application interdit
 * (TransactionService::delete()/annuler() portent déjà ce contrôle — voir
 * ExerciceVerrouServiceTest pour la couverture générique de delete()).
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('refuse une acquisition dans un exercice clôturé, sans laisser de fiche ni d’écriture', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel acheté trop tard',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'), // exercice 2026, clôturé
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(ExerciceCloturedException::class);

    expect(Immobilisation::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

it('accepte une acquisition dans un exercice ouvert', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);

    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    );

    expect($immo->numero)->toBe('IM00001')
        ->and(Transaction::count())->toBe(1);
});

it('refuse de modifier une fiche dont l’exercice d’acquisition est clôturé', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
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

    // L'exercice n'est clôturé qu'après l'acquisition : la fiche existe déjà.
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    expect(fn () => app(ImmobilisationService::class)->modifier(
        immobilisation: $immo,
        libelle: $immo->libelle,
        quantite: $immo->quantite,
        dureeMois: $immo->duree_mois,
        dateMiseEnService: Carbon::parse('2026-10-01'),
        notes: null,
    ))->toThrow(ExerciceCloturedException::class);

    $fiche = $immo->fresh();
    expect($fiche->date_mise_en_service->toDateString())->toBe('2026-09-12')
        ->and($fiche->libelle)->toBe('20 tenues d’escrime');
});
