<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RapprochementBancaireService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
    $this->service = app(RapprochementBancaireService::class);
});

test('calculerSoldeOuverture retourne solde_initial si aucun rapprochement verrouillé', function () {
    $solde = $this->service->calculerSoldeOuverture($this->compte);
    expect($solde)->toBe(1000.0);
});

test('calculerSoldeOuverture retourne solde_fin du dernier rapprochement verrouillé', function () {
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'solde_fin' => 1500.00,
        'statut' => StatutRapprochement::Verrouille,
        'date_fin' => '2025-10-31',
        'saisi_par' => $this->user->id,
    ]);
    $solde = $this->service->calculerSoldeOuverture($this->compte);
    expect($solde)->toBe(1500.0);
});

test('create crée un rapprochement avec le bon solde_ouverture', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1200.00);

    expect($rapprochement->statut)->toBe(StatutRapprochement::EnCours)
        ->and((float) $rapprochement->solde_ouverture)->toBe(1000.0)
        ->and((float) $rapprochement->solde_fin)->toBe(1200.0);
});

test('create échoue si un rapprochement en cours existe déjà', function () {
    $this->service->create($this->compte, '2025-10-31', 1200.00);

    expect(fn () => $this->service->create($this->compte, '2025-11-30', 1300.00))
        ->toThrow(RuntimeException::class);
});

test('calculerSoldePointage prend en compte les recettes pointées', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1500.00);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 300.00,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($solde)->toBe(1300.0); // 1000 + 300
});

test('calculerSoldePointage déduit les dépenses pointées', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 800.00);
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($solde)->toBe(800.0); // 1000 - 200
});

test('toggleTransaction ajoute une dépense au rapprochement', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 800.00);
    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'pointe' => false,
    ]);

    $this->service->toggleTransaction($rapprochement, 'depense', $depense->id);

    expect($depense->fresh()->rapprochement_id)->toBe($rapprochement->id)
        ->and($depense->fresh()->pointe)->toBeTrue();
});

test('toggleTransaction retire une dépense déjà pointée', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 800.00);
    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $this->service->toggleTransaction($rapprochement, 'depense', $depense->id);

    expect($depense->fresh()->rapprochement_id)->toBeNull()
        ->and($depense->fresh()->pointe)->toBeFalse();
});

test('verrouiller échoue si écart non nul', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1500.00);

    expect(fn () => $this->service->verrouiller($rapprochement))
        ->toThrow(RuntimeException::class);
});

test('verrouiller réussit quand écart = 0', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);
    // solde_ouverture = 1000, solde_fin = 1000, aucune transaction → écart = 0

    $this->service->verrouiller($rapprochement);

    expect($rapprochement->fresh()->statut)->toBe(StatutRapprochement::Verrouille)
        ->and($rapprochement->fresh()->verrouille_at)->not->toBeNull();
});

test('toggleTransaction lève une exception si rapprochement verrouillé', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'saisi_par' => $this->user->id,
        'verrouille_at' => now(),
    ]);
    $depense = Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id]);

    expect(fn () => $this->service->toggleTransaction($rapprochement, 'depense', $depense->id))
        ->toThrow(RuntimeException::class);
});

test('supprimer supprime un rapprochement en cours et dépointe les opérations', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);
    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $this->service->supprimer($rapprochement);

    expect(RapprochementBancaire::find($rapprochement->id))->toBeNull()
        ->and($depense->fresh()->rapprochement_id)->toBeNull()
        ->and($depense->fresh()->pointe)->toBeFalse()
        ->and($recette->fresh()->rapprochement_id)->toBeNull()
        ->and($recette->fresh()->pointe)->toBeFalse();
});

test('supprimer lève une exception si le rapprochement est verrouillé', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'saisi_par' => $this->user->id,
        'verrouille_at' => now(),
    ]);

    expect(fn () => $this->service->supprimer($rapprochement))
        ->toThrow(RuntimeException::class);
});

test('supprimer dépointe aussi les dons et cotisations', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);
    $don = Don::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);
    $tiers = Tiers::factory()->membre()->create();
    $cotisation = Cotisation::factory()->create([
        'tiers_id' => $tiers->id,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $this->service->supprimer($rapprochement);

    expect($don->fresh()->rapprochement_id)->toBeNull()
        ->and($don->fresh()->pointe)->toBeFalse()
        ->and($cotisation->fresh()->rapprochement_id)->toBeNull()
        ->and($cotisation->fresh()->pointe)->toBeFalse();
});

test('deverrouiller déverrouille le dernier rapprochement si aucun en cours', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-10-31',
    ]);

    $this->service->deverrouiller($rapprochement);

    expect($rapprochement->fresh()->statut)->toBe(StatutRapprochement::EnCours)
        ->and($rapprochement->fresh()->verrouille_at)->toBeNull();
});

test('deverrouiller lève une exception si un rapprochement est en cours sur ce compte', function () {
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-11-30',
    ]);
    $verrouille = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-10-31',
    ]);

    expect(fn () => $this->service->deverrouiller($verrouille))
        ->toThrow(RuntimeException::class);
});

test('deverrouiller lève une exception si ce n\'est pas le dernier verrouillé', function () {
    $premier = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now()->subDay(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-09-30',
    ]);
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-10-31',
    ]);

    expect(fn () => $this->service->deverrouiller($premier))
        ->toThrow(RuntimeException::class);
});

test('deverrouiller lève une exception si le rapprochement est en cours', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);

    expect(fn () => $this->service->deverrouiller($rapprochement))
        ->toThrow(RuntimeException::class);
});
