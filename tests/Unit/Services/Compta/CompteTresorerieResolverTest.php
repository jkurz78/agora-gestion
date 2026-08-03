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

    // Le compte 512X est créé par CompteBancaireObserver à la création de la
    // fiche bancaire. Il porte compte_bancaire_id — la clé stable qu'utilise le
    // resolver, l'IBAN étant nullable et non unique.
    $this->compte512X = Compte::where('compte_bancaire_id', (int) $this->compteBancaire->id)->sole();
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

/**
 * Une fiche bancaire privée de son compte 512X.
 *
 * CompteBancaireObserver en dote désormais toute fiche nouvelle ; il faut donc
 * défaire son travail pour atteindre cette branche. Elle reste vivante en
 * production : les fiches créées avant l'observer, et celles dont le compte a
 * été archivé, y passent encore.
 */
function compteBancaireSans512X(string $iban): CompteBancaire
{
    $compteBancaire = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
        'iban' => $iban,
    ]);

    Compte::where('compte_bancaire_id', (int) $compteBancaire->id)->delete();

    return $compteBancaire;
}

it('[R6] compteBancaireId non-null + IBAN no-match + mode Virement → null + Log::warning', function () {
    Log::spy();

    $compteBancaireSans512X = compteBancaireSans512X('FR7699999999999999999999999');

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
    $compteBancaireSans512X = compteBancaireSans512X('FR7699999999999999999999998');

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

    $compteBancaireSans512X = compteBancaireSans512X('FR7699999999999999999999997');

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
