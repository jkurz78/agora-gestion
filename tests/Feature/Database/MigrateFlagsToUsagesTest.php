<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('migrates existing flags to pivot rows exactly once', function () {
    $asso = \App\Models\Association::factory()->create();
    \App\Tenant\TenantContext::boot($asso);
    (new \App\Services\Onboarding\DefaultChartOfAccountsService())->applyTo($asso);

    $flagsCount = DB::table('sous_categories')
        ->where('association_id', $asso->id)
        ->where(function ($q) {
            $q->where('pour_dons', true)
              ->orWhere('pour_cotisations', true)
              ->orWhere('pour_inscriptions', true)
              ->orWhere('pour_frais_kilometriques', true);
        })->count();
    expect($flagsCount)->toBeGreaterThan(0);

    // Purger + re-jouer la data migration (simuler un rejeu)
    DB::table('usages_sous_categories')->where('association_id', $asso->id)->delete();

    $migration = include database_path('migrations/2026_04_21_120100_migrate_sous_categorie_flags_to_usages.php');
    $migration->up();
    $initialCount = DB::table('usages_sous_categories')->where('association_id', $asso->id)->count();

    // Rejouer : aucun doublon
    $migration->up();
    $afterReplayCount = DB::table('usages_sous_categories')->where('association_id', $asso->id)->count();

    expect($afterReplayCount)->toBe($initialCount);
});
