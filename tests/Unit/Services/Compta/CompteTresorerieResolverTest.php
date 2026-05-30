<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Services\Compta\CompteTresorerieResolver;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    // GlobalBeforeEach de Pest.php a déjà booté TenantContext + RefreshDatabase.
    // On seed les comptes système (5112, 411, 401) pour les branches placeholder.
    SystemeSeeder::seed();

    // CompteBancaire avec IBAN connu → Compte 512X correspondant
    $this->iban = 'FR7612345000012345678901234';

    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
        'iban' => $this->iban,
    ]);

    $this->compte512X = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '5121',
        'intitule' => 'Banque principale',
        'classe' => 5,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'iban' => $this->iban,
        // Clé stable utilisée par le resolver (l'IBAN est nullable et non unique).
        'compte_bancaire_id' => $this->compteBancaire->id,
    ]);
});

// ---------------------------------------------------------------------------
// Branche : compteBancaireId null
// ---------------------------------------------------------------------------

it('[R1] compteBancaireId null + mode Especes → placeholder 5112 retourné', function () {
    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: null,
        mode: ModePaiement::Especes,
        contextLog: 'TestR1',
        sens: Sens::Recette,
    );

    expect($result)->not->toBeNull();
    expect($result->numero_pcg)->toBe('5112');
});

it('[R2] compteBancaireId null + mode Virement → null + Log::warning', function () {
    Log::spy();

    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: null,
        mode: ModePaiement::Virement,
        contextLog: 'TestR2',
        sens: Sens::Recette,
    );

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return str_contains($message, 'TestR2') && str_contains($message, 'compte_id null');
        });
});

it('[R3] compteBancaireId null + mode Cheque + Sens::Depense → null + Log::warning', function () {
    Log::spy();

    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: null,
        mode: ModePaiement::Cheque,
        contextLog: 'TestR3',
        sens: Sens::Depense,
    );

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return str_contains($message, 'TestR3') && str_contains($message, 'compte_id null');
        });
});

it('[R4] compteBancaireId null + mode Cheque + Sens::Recette → placeholder 5112', function () {
    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: null,
        mode: ModePaiement::Cheque,
        contextLog: 'TestR4',
        sens: Sens::Recette,
    );

    expect($result)->not->toBeNull();
    expect($result->numero_pcg)->toBe('5112');
});

// ---------------------------------------------------------------------------
// Branche : compteBancaireId non-null + IBAN match
// ---------------------------------------------------------------------------

it('[R5] compteBancaireId non-null + IBAN match + mode Virement → Compte 512X résolu', function () {
    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: (int) $this->compteBancaire->id,
        mode: ModePaiement::Virement,
        contextLog: 'TestR5',
        sens: Sens::Recette,
    );

    expect($result)->not->toBeNull();
    expect((int) $result->id)->toBe((int) $this->compte512X->id);
    expect($result->numero_pcg)->toBe('5121');
});

// ---------------------------------------------------------------------------
// Branche : compteBancaireId non-null + IBAN no-match
// ---------------------------------------------------------------------------

it('[R6] compteBancaireId non-null + IBAN no-match + mode Virement → null + Log::warning', function () {
    Log::spy();

    // CompteBancaire sans Compte 512X correspondant (IBAN différent)
    $compteBancaireSans512X = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
        'iban' => 'FR7699999999999999999999999',
    ]);

    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: (int) $compteBancaireSans512X->id,
        mode: ModePaiement::Virement,
        contextLog: 'TestR6',
        sens: Sens::Recette,
    );

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return str_contains($message, 'TestR6') && str_contains($message, '512X introuvable');
        });
});

it('[R7] compteBancaireId non-null + IBAN no-match + mode Especes → placeholder 5112', function () {
    $compteBancaireSans512X = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
        'iban' => 'FR7699999999999999999999998',
    ]);

    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: (int) $compteBancaireSans512X->id,
        mode: ModePaiement::Especes,
        contextLog: 'TestR7',
        sens: Sens::Recette,
    );

    expect($result)->not->toBeNull();
    expect($result->numero_pcg)->toBe('5112');
});

it('[R8] compteBancaireId non-null + IBAN no-match + mode Cheque + Sens::Depense → null + Log::warning', function () {
    Log::spy();

    $compteBancaireSans512X = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
        'iban' => 'FR7699999999999999999999997',
    ]);

    $result = CompteTresorerieResolver::resoudre(
        compteBancaireId: (int) $compteBancaireSans512X->id,
        mode: ModePaiement::Cheque,
        contextLog: 'TestR8',
        sens: Sens::Depense,
    );

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return str_contains($message, 'TestR8') && str_contains($message, '512X introuvable');
        });
});
