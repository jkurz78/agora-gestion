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
