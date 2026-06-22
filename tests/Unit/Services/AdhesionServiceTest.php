<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\AdhesionService;

it('creerDepuisTransaction crée une adhésion pour une transaction cotisation', function (): void {
    $service = app(AdhesionService::class);

    $sc = SousCategorie::factory()->pourCotisations()->create();
    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-10-15',
    ]);
    // Replace auto-created lignes with a cotisation ligne
    TransactionLigne::where('transaction_id', $tx->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
    ]);

    $adhesion = $service->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    expect($adhesion->tiers_id)->toBe($tiers->id);
    expect($adhesion->exercice)->toBe(2025);
    expect($adhesion->estGratuite())->toBeFalse();
    expect($adhesion->transaction_id)->toBe($tx->id);
    expect($adhesion->formule_adhesion_id)->toBeNull();
    expect(Adhesion::count())->toBe(1);
});

it('creerDepuisTransaction est idempotent', function (): void {
    $service = app(AdhesionService::class);

    $sc = SousCategorie::factory()->pourCotisations()->create();
    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
    ]);

    $service->creerDepuisTransaction($tx);
    $service->creerDepuisTransaction($tx);

    expect(Adhesion::count())->toBe(1);
});

it('creerDepuisTransaction ne duplique pas une adhésion déjà liée à la transaction (exercice différent)', function (): void {
    // Régression : adhésion saisie via le wizard en mode durée (exercice=NULL),
    // puis "marquer reçu" rejoue creerDepuisTransaction. L'ancien lookup par
    // exercice (calculé à 2025) ne retrouvait pas l'adhésion exercice=NULL liée
    // à la même transaction → doublon. Le bon idempotent = transaction_id.
    $service = app(AdhesionService::class);

    $sc = SousCategorie::factory()->pourCotisations()->create();
    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'date' => '2026-06-20',
    ]);
    // Ligne cotisation posée sans déclencher l'observer (comme le wizard).
    TransactionLigne::withoutEvents(function () use ($tx, $sc): void {
        TransactionLigne::where('transaction_id', $tx->id)->delete();
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'sous_categorie_id' => $sc->id,
        ]);
    });

    // Adhésion déjà créée par le wizard pour CETTE transaction, mode durée → exercice NULL.
    $existante = Adhesion::create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'exercice' => null,
        'transaction_id' => $tx->id,
        'mode' => 'duree',
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'montant_facial' => 25.00,
        'label_formule' => 'Adhésion saison 2025-2026',
    ]);

    // L'observer rejoue creerDepuisTransaction (ex. au "marquer reçu").
    $result = $service->creerDepuisTransaction($tx);

    expect(Adhesion::count())->toBe(1);
    expect($result->id)->toBe($existante->id);
});

it('creerDepuisTransaction retourne null si pas de ligne cotisation', function (): void {
    $service = app(AdhesionService::class);

    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-10-15',
    ]);
    // Remove all lignes — no cotisation
    TransactionLigne::where('transaction_id', $tx->id)->delete();

    $result = $service->creerDepuisTransaction($tx);

    expect($result)->toBeNull();
    expect(Adhesion::count())->toBe(0);
});

it('creerGratuite crée une adhésion sans transaction', function (): void {
    $service = app(AdhesionService::class);

    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $adhesion = $service->creerGratuite($tiers, 2025, 'Membre d\'honneur', $user);

    expect($adhesion->estGratuite())->toBeTrue();
    expect($adhesion->transaction_id)->toBeNull();
    expect($adhesion->notes)->toBe('Membre d\'honneur');
    expect($adhesion->exercice)->toBe(2025);
    expect($adhesion->tiers_id)->toBe($tiers->id);
    expect(Adhesion::count())->toBe(1);
});

it('creerGratuite refuse un doublon', function (): void {
    $service = app(AdhesionService::class);

    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $service->creerGratuite($tiers, 2025, 'Membre d\'honneur', $user);

    expect(fn () => $service->creerGratuite($tiers, 2025, 'Deuxième tentative', $user))
        ->toThrow(DomainException::class);
});

it('creerGratuite restore une adhésion soft-deleted plutôt que d\'en créer une nouvelle', function (): void {
    $service = app(AdhesionService::class);

    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $adhesion = $service->creerGratuite($tiers, 2025, 'Ancien motif', $user);
    $adhesion->delete();

    expect(Adhesion::count())->toBe(0);
    expect(Adhesion::withTrashed()->count())->toBe(1);

    $restored = $service->creerGratuite($tiers, 2025, 'Nouveau motif', $user);

    expect(Adhesion::count())->toBe(1);
    expect(Adhesion::withTrashed()->count())->toBe(1);
    expect($restored->id)->toBe($adhesion->id);
    expect($restored->notes)->toBe('Nouveau motif');
    expect($restored->estGratuite())->toBeTrue();
});
