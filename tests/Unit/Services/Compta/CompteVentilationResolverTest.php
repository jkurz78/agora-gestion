<?php

declare(strict_types=1);

use App\Models\Categorie;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Services\Compta\CompteVentilationResolver;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    // GlobalBeforeEach de Pest.php a déjà booté TenantContext + RefreshDatabase.
    // On seed les comptes système pour avoir un tenant cohérent.
    SystemeSeeder::seed();

    $tenantId = (int) TenantContext::currentId();

    // Catégorie recette pour les sous-catégories de classe 7
    $catRecette = Categorie::factory()->recette()->create([
        'association_id' => $tenantId,
        'nom' => 'Prestations',
    ]);

    // Catégorie dépense pour les sous-catégories de classe 6
    $catDepense = Categorie::factory()->depense()->create([
        'association_id' => $tenantId,
        'nom' => 'Charges',
    ]);

    // Compte 706 (classe 7 — recette)
    $this->compte706 = Compte::create([
        'association_id' => $tenantId,
        'numero_pcg' => '706',
        'intitule' => 'Prestations de services',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'categorie_id' => $catRecette->id,
    ]);

    // Compte 606 (classe 6 — dépense)
    $this->compte606 = Compte::create([
        'association_id' => $tenantId,
        'numero_pcg' => '606',
        'intitule' => 'Achats fournitures',
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'categorie_id' => $catDepense->id,
    ]);

    // Sous-catégorie avec code_cerfa = '706' (mappe sur classe 7)
    $this->sc706 = SousCategorie::create([
        'association_id' => $tenantId,
        'categorie_id' => $catRecette->id,
        'nom' => 'Cotisations',
        'code_cerfa' => '706',
    ]);

    // Sous-catégorie avec code_cerfa = '606' (mappe sur classe 6)
    $this->sc606 = SousCategorie::create([
        'association_id' => $tenantId,
        'categorie_id' => $catDepense->id,
        'nom' => 'Fournitures',
        'code_cerfa' => '606',
    ]);

    // Sous-catégorie sans code_cerfa
    $this->scSansCode = SousCategorie::create([
        'association_id' => $tenantId,
        'categorie_id' => $catRecette->id,
        'nom' => 'Divers sans code',
        'code_cerfa' => null,
    ]);

    // Sous-catégorie avec code_cerfa introuvable dans comptes
    $this->scCodeIntrouvable = SousCategorie::create([
        'association_id' => $tenantId,
        'categorie_id' => $catRecette->id,
        'nom' => 'Compte inconnu',
        'code_cerfa' => '999',
    ]);
});

// ---------------------------------------------------------------------------
// [V1] Résolution correcte classe 7 (recette)
// ---------------------------------------------------------------------------

it('[V1] sousCategorieId valide → compte classe 7 → retourné', function () {
    $result = CompteVentilationResolver::resoudre(
        sousCategorieId: (int) $this->sc706->id,
        classeAttendue: 7,
        contextLog: 'TestStep',
    );

    expect($result)->toBeInstanceOf(Compte::class)
        ->and((int) $result->id)->toBe((int) $this->compte706->id)
        ->and($result->numero_pcg)->toBe('706');
});

// ---------------------------------------------------------------------------
// [V2] Résolution correcte classe 6 (dépense)
// ---------------------------------------------------------------------------

it('[V2] sousCategorieId valide → compte classe 6 → retourné', function () {
    $result = CompteVentilationResolver::resoudre(
        sousCategorieId: (int) $this->sc606->id,
        classeAttendue: 6,
        contextLog: 'TestStep',
    );

    expect($result)->toBeInstanceOf(Compte::class)
        ->and((int) $result->id)->toBe((int) $this->compte606->id)
        ->and($result->numero_pcg)->toBe('606');
});

// ---------------------------------------------------------------------------
// [V3] sousCategorieId null → null + pas de log
// ---------------------------------------------------------------------------

it('[V3] sousCategorieId null → null retourné (garde early return)', function () {
    Log::spy();

    $result = CompteVentilationResolver::resoudre(
        sousCategorieId: null,
        classeAttendue: 7,
        contextLog: 'TestStep',
    );

    expect($result)->toBeNull();
    // Pas de Log::warning pour null (appelant gère le log si nécessaire)
});

// ---------------------------------------------------------------------------
// [V4] Sous-catégorie sans code_cerfa → null + Log::warning
// ---------------------------------------------------------------------------

it('[V4] sous-catégorie sans code_cerfa → null + Log::warning', function () {
    Log::spy();

    $result = CompteVentilationResolver::resoudre(
        sousCategorieId: (int) $this->scSansCode->id,
        classeAttendue: 7,
        contextLog: 'TestStep27',
    );

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'TestStep27') && str_contains($msg, 'code_cerfa'));
});

// ---------------------------------------------------------------------------
// [V5] code_cerfa introuvable dans comptes → null + Log::warning
// ---------------------------------------------------------------------------

it('[V5] code_cerfa introuvable dans comptes → null + Log::warning', function () {
    Log::spy();

    $result = CompteVentilationResolver::resoudre(
        sousCategorieId: (int) $this->scCodeIntrouvable->id,
        classeAttendue: 7,
        contextLog: 'TestStep27',
    );

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'TestStep27') && str_contains($msg, 'introuvable'));
});

// ---------------------------------------------------------------------------
// [V6] Classe compte ≠ attendue (706 vs classe 6 attendue) → null + Log::warning
// ---------------------------------------------------------------------------

it('[V6] compte trouvé mais classe différente de attendue → null + Log::warning', function () {
    Log::spy();

    // sc706 pointe sur compte706 (classe 7), on demande classe 6 → mismatch
    $result = CompteVentilationResolver::resoudre(
        sousCategorieId: (int) $this->sc706->id,
        classeAttendue: 6,
        contextLog: 'TestStep27',
    );

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'TestStep27') && str_contains($msg, 'classe'));
});

// ---------------------------------------------------------------------------
// [V7] contextLogData transmis dans le warning (log enrichi)
// ---------------------------------------------------------------------------

it('[V7] contextLogData transmis dans le Log::warning', function () {
    Log::spy();

    CompteVentilationResolver::resoudre(
        sousCategorieId: (int) $this->scSansCode->id,
        classeAttendue: 7,
        contextLog: 'TestStep27',
        contextLogData: ['transaction_id' => 42],
    );

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function ($msg, $context) {
            return isset($context['transaction_id']) && $context['transaction_id'] === 42;
        });
});

// ---------------------------------------------------------------------------
// [V8] sousCategorieId non-existant en base → null + Log::warning
// ---------------------------------------------------------------------------

it('[V8] sousCategorieId inexistant en base → null + Log::warning', function () {
    Log::spy();

    $result = CompteVentilationResolver::resoudre(
        sousCategorieId: 99999,
        classeAttendue: 7,
        contextLog: 'TestStep27',
    );

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'TestStep27'));
});
