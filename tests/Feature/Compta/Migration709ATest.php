<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\UsageCompte;
use App\Services\Onboarding\DefaultChartOfAccountsService;
use App\Tenant\TenantContext;

it('le plan comptable par défaut contient 709A rattaché à l’usage Gratuite', function (): void {
    $asso = Association::factory()->create();
    // CompteObserver::materialiserFamille() fait un Famille::firstOrCreate() —
    // Famille est un TenantModel à scope global fail-closed. Sans booter le
    // TenantContext sur $asso, le SELECT du firstOrCreate est filtré sur le
    // tenant globalement booté par tests/Pest.php (un autre) et ne trouve
    // jamais la famille déjà créée, provoquant une violation de contrainte
    // unique dès le second compte de la même famille. Voir le même motif
    // dans DefaultChartOfAccountsSeedTest.php.
    TenantContext::boot($asso);
    app(DefaultChartOfAccountsService::class)->applyTo($asso);

    $compte = Compte::withoutGlobalScopes()
        ->where('association_id', $asso->id)
        ->where('numero_pcg', '709A')
        ->first();

    expect($compte)->not->toBeNull()
        ->and((int) $compte->classe)->toBe(7)
        ->and($compte->intitule)->toBe('Gratuités accordées')
        ->and((bool) $compte->est_systeme)->toBeFalse();

    // pluck() applique les casts du modèle (Builder::pluck() hydrate un modèle
    // dès qu'un cast existe sur la colonne) : ->all() contient des instances
    // UsageComptable, pas les valeurs scalaires brutes de la colonne.
    $usages = UsageCompte::withoutGlobalScopes()
        ->where('compte_id', $compte->id)
        ->pluck('usage')
        ->all();

    expect($usages)->toContain(UsageComptable::Gratuite);
});

it('la migration de rattrapage 709A est idempotente : rejouée plusieurs fois, ne duplique ni compte ni usage', function (): void {
    $associationId = TenantContext::currentId();

    // La migration a déjà tourné une première fois lors du migrate initial
    // de RefreshDatabase (table `association` vide à ce moment-là, donc
    // no-op). On la rejoue explicitement 3 fois sur le tenant du test.
    $migration = require database_path('migrations/2026_08_20_100000_add_compte_709a_gratuites.php');

    $migration->up();
    $migration->up();
    $migration->up();

    $comptes = Compte::withoutGlobalScopes()
        ->where('association_id', $associationId)
        ->where('numero_pcg', '709A')
        ->get();

    expect($comptes)->toHaveCount(1);

    $usages = UsageCompte::withoutGlobalScopes()
        ->where('compte_id', $comptes->first()->id)
        ->where('usage', UsageComptable::Gratuite->value)
        ->get();

    expect($usages)->toHaveCount(1);
});

it('la migration de rattrapage 709A réutilise un compte 709A déjà saisi à la main sans le dupliquer', function (): void {
    $associationId = TenantContext::currentId();

    $compteManuel = Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => '709A',
        'intitule' => 'Gratuités (saisi à la main)',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    $migration = require database_path('migrations/2026_08_20_100000_add_compte_709a_gratuites.php');

    $migration->up();
    $migration->up();

    $comptes = Compte::withoutGlobalScopes()
        ->where('association_id', $associationId)
        ->where('numero_pcg', '709A')
        ->get();

    expect($comptes)->toHaveCount(1)
        ->and($comptes->first()->id)->toBe($compteManuel->id)
        ->and($comptes->first()->intitule)->toBe('Gratuités (saisi à la main)');

    $usages = UsageCompte::withoutGlobalScopes()
        ->where('compte_id', $compteManuel->id)
        ->where('usage', UsageComptable::Gratuite->value)
        ->get();

    expect($usages)->toHaveCount(1);
});
