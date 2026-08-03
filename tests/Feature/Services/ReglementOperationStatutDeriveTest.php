<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\LettrageService;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('marquerRecu chèque sur créance → statut dérivé EnMain', function () {
    $creance = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Créance à encaisser',
        'montant_total' => '100.00',
        'mode_paiement' => null,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);
    expect($creance->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);

    app(ReglementOperationService::class)->marquerRecu(
        $creance->fresh(), ModePaiement::Cheque, (int) $this->compteBancaire->id
    );

    expect($creance->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('marquerRecu virement sur créance → statut dérivé Recu', function () {
    $creance = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Créance virement',
        'montant_total' => '100.00',
        'mode_paiement' => null,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    app(ReglementOperationService::class)->marquerRecu(
        $creance->fresh(), ModePaiement::Virement, (int) $this->compteBancaire->id
    );

    expect($creance->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('marquerPaye virement sur dette → statut dérivé Recu (réglé)', function () {
    $dette = app(TransactionService::class)->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Dette à régler',
        'montant_total' => '50.00',
        'mode_paiement' => null,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], [[
        'compte_id' => $this->compte606->id,
        'montant' => '50.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    app(ReglementOperationService::class)->marquerPaye(
        $dette->fresh(), ModePaiement::Virement, (int) $this->compteBancaire->id
    );

    expect($dette->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('agrège plusieurs T2 bancaires en Recu puis Pointe seulement quand toutes sont rapprochées', function () {
    $t1 = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-15'),
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente]);
    $ligne411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $service = app(PosteTiersReglementService::class);

    $t2Premiere = $service->regler(new PosteTiersReglementData(
        ligneId: (int) $ligne411->id,
        montantCentimes: 3000,
        date: CarbonImmutable::parse('2026-07-21'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    ));
    $t2Seconde = $service->regler(new PosteTiersReglementData(
        ligneId: (int) $ligne411->id,
        montantCentimes: 7000,
        date: CarbonImmutable::parse('2026-07-22'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    ));

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);

    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'saisi_par' => $this->user->id,
    ]);
    $t2Premiere->update(['rapprochement_id' => (int) $rapprochement->id]);
    app(EtatReglementResolver::class)->syncer($t1->fresh());

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);

    $t2Seconde->update(['rapprochement_id' => (int) $rapprochement->id]);
    app(EtatReglementResolver::class)->syncer($t1->fresh());

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Pointe);
});

it('agrège plusieurs T2 en EnMain si au moins un portage reste non remis', function () {
    $t1 = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-15'),
    );
    $t1->update(['statut_reglement' => StatutReglement::EnAttente]);
    $ligne411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $service = app(PosteTiersReglementService::class);

    $service->regler(new PosteTiersReglementData(
        ligneId: (int) $ligne411->id,
        montantCentimes: 3000,
        date: CarbonImmutable::parse('2026-07-21'),
        mode: ModePaiement::Cheque,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    ));
    $service->regler(new PosteTiersReglementData(
        ligneId: (int) $ligne411->id,
        montantCentimes: 7000,
        date: CarbonImmutable::parse('2026-07-22'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    ));

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('agrège une branche lumped en main avec une T2 séparée pointée', function (?ModePaiement $modeSource) {
    $t1 = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-15'),
    );
    $t1->update([
        'statut_reglement' => StatutReglement::EnAttente,
        'mode_paiement' => $modeSource,
    ]);
    $ligne411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $ligne411->update(['debit' => '30.00']);
    $fraction = $ligne411->replicate(['id', 'lettrage_code', 'deleted_at']);
    $fraction->fill([
        'debit' => '70.00',
        'credit' => '0.00',
        'poste_tiers_parent_id' => (int) $ligne411->id,
    ]);
    $fraction->save();

    $ligneLumped = TransactionLigne::create([
        'transaction_id' => (int) $t1->id,
        'compte_id' => (int) $ligne411->compte_id,
        'debit' => '0.00',
        'credit' => '30.00',
        'tiers_id' => (int) $ligne411->tiers_id,
        'libelle' => 'Contrepartie lumped',
        'montant' => '0.00',
    ]);
    TransactionLigne::create([
        'transaction_id' => (int) $t1->id,
        'compte_id' => (int) compteSysteme('5112')->id,
        'debit' => '30.00',
        'credit' => '0.00',
        'libelle' => 'Chèque en main',
        'montant' => '0.00',
    ]);
    app(LettrageService::class)->lettrer(
        collect([$ligne411->fresh(), $ligneLumped]),
    );

    $t2 = app(PosteTiersReglementService::class)->regler(new PosteTiersReglementData(
        ligneId: (int) $fraction->id,
        montantCentimes: 7000,
        date: CarbonImmutable::parse('2026-07-22'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    ));
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'saisi_par' => $this->user->id,
    ]);
    $t2->update(['rapprochement_id' => (int) $rapprochement->id]);

    app(EtatReglementResolver::class)->syncer($t1->fresh());

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain)
        ->and(app(PostesTiersOuvertsService::class)->reglements($t1->fresh()))
        ->toHaveCount(2);
})->with([
    'mode explicite' => ModePaiement::Cheque,
    'branche legacy sans mode' => null,
]);
