<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Adhesion;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
    $this->tiers = Tiers::factory()->create();
    $this->user = User::factory()->create();
    $this->compte = CompteBancaire::factory()->create();
});

it('crée une adhésion gratuite (montant=0, pas de transaction)', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'exercice',
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 0,
        notes: "Membre d'honneur",
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    $service = app(AdhesionService::class);
    $adhesion = $service->creerDepuisWizard($dto, $this->user);

    expect($adhesion->transaction_id)->toBeNull();
    expect((int) $adhesion->formule_adhesion_id)->toBe((int) $formule->id);
    expect($adhesion->exercice)->toBe(2025);
    expect($adhesion->notes)->toBe("Membre d'honneur");
    expect(Transaction::count())->toBe(0);
});

it('crée une adhésion payée (montant > 0, transaction créée)', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'exercice',
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 30,
        notes: null,
        datePaiement: '2025-10-01',
        modePaiement: ModePaiement::Cb,
        compteId: (int) $this->compte->id,
        reference: 'REF-001',
    );

    $service = app(AdhesionService::class);
    $adhesion = $service->creerDepuisWizard($dto, $this->user);

    expect($adhesion->transaction_id)->not->toBeNull();
    expect((int) $adhesion->formule_adhesion_id)->toBe((int) $formule->id);

    $tx = Transaction::findOrFail($adhesion->transaction_id);
    expect((float) $tx->montant_total)->toBe(30.00);
    expect($tx->reference)->toBe('REF-001');

    $ligne = $tx->lignes()->first();
    expect($ligne)->not->toBeNull();
    expect((int) $ligne->sous_categorie_id)->toBe((int) $this->sc->id);
});

it('crée une adhésion mode durée avec date_debut/date_fin calculées', function (): void {
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: null,
        dateDebut: Carbon::parse('2025-10-15'),
        montant: 0,
        notes: null,
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    $service = app(AdhesionService::class);
    $adhesion = $service->creerDepuisWizard($dto, $this->user);

    expect($adhesion->date_debut->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin->toDateString())->toBe('2026-10-14');
    expect($adhesion->exercice)->toBeNull();
});

it('refuse un doublon en mode exercice', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'exercice',
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 0,
        notes: null,
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    $service = app(AdhesionService::class);
    $service->creerDepuisWizard($dto, $this->user);

    expect(fn () => $service->creerDepuisWizard($dto, $this->user))
        ->toThrow(DomainException::class, 'déjà une adhésion');
});

it('refuse un recouvrement en mode durée', function (): void {
    $formule = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
    ]);

    $service = app(AdhesionService::class);

    // Première adhésion: 2025-10-15 → 2026-10-15
    $dto1 = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: null,
        dateDebut: Carbon::parse('2025-10-15'),
        montant: 0,
        notes: null,
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );
    $service->creerDepuisWizard($dto1, $this->user);

    // Deuxième adhésion: 2026-06-01 → 2027-06-01 (chevauche la première)
    $dto2 = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: null,
        dateDebut: Carbon::parse('2026-06-01'),
        montant: 0,
        notes: null,
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    expect(fn () => $service->creerDepuisWizard($dto2, $this->user))
        ->toThrow(DomainException::class, 'chevauche');
});

it('création atomique : si la transaction échoue, pas d\'adhésion orpheline', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'exercice',
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 30,
        notes: null,
        datePaiement: '2025-10-01',
        modePaiement: ModePaiement::Cb,
        compteId: 999999, // FK invalide → doit provoquer une erreur
        reference: null,
    );

    $service = app(AdhesionService::class);

    expect(fn () => $service->creerDepuisWizard($dto, $this->user))
        ->toThrow(Exception::class);

    expect(Adhesion::count())->toBe(0);
    expect(Transaction::count())->toBe(0);
});

it('lève une InvalidArgumentException si montant > 0 et datePaiement est null', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 30.00,
        notes: null,
        datePaiement: null, // manquant intentionnellement
        modePaiement: ModePaiement::Cb,
        compteId: (int) $this->compte->id,
        reference: null,
    );

    expect(fn () => app(AdhesionService::class)->creerDepuisWizard($dto, $this->user))
        ->toThrow(InvalidArgumentException::class, 'datePaiement');
});

it('crée une adhésion mode illimite (permanente)', function (): void {
    $formule = FormuleAdhesion::factory()->modeIllimite()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Membre à vie',
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: null,
        dateDebut: Carbon::parse('2025-10-15'),
        montant: 0.00,
        notes: 'Membre fondateur',
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    $adhesion = app(AdhesionService::class)->creerDepuisWizard($dto, $this->user);

    expect($adhesion->mode)->toBe('illimite');
    expect($adhesion->date_debut?->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin)->toBeNull();
    expect($adhesion->exercice)->toBeNull();
});

it('refuse une adhésion si une adhésion soft-deleted existe pour le même exercice', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
    ]);

    // Créer puis soft-deleter une adhésion sur 2025
    $existante = Adhesion::factory()->create([
        'tiers_id' => $this->tiers->id,
        'exercice' => 2025,
    ]);
    $existante->delete();

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 0.00,
        notes: 'Tentative de re-création',
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    expect(fn () => app(AdhesionService::class)->creerDepuisWizard($dto, $this->user))
        ->toThrow(DomainException::class, 'annulée');
});
