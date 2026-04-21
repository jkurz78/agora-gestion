<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use Illuminate\Support\Facades\DB;

it('DefaultChartOfAccountsService crée des pivot rows pour dons et cotisations', function () {
    $asso = \App\Models\Association::factory()->create();
    \App\Tenant\TenantContext::boot($asso);
    (new \App\Services\Onboarding\DefaultChartOfAccountsService())->applyTo($asso);

    // Vérifier que les usages attendus ont été créés via pivot
    $donCount = DB::table('usages_sous_categories')
        ->where('association_id', $asso->id)
        ->where('usage', UsageComptable::Don->value)
        ->count();
    $cotCount = DB::table('usages_sous_categories')
        ->where('association_id', $asso->id)
        ->where('usage', UsageComptable::Cotisation->value)
        ->count();
    $inscrCount = DB::table('usages_sous_categories')
        ->where('association_id', $asso->id)
        ->where('usage', UsageComptable::Inscription->value)
        ->count();

    expect($donCount)->toBeGreaterThan(0);
    expect($cotCount)->toBeGreaterThan(0);
    expect($inscrCount)->toBeGreaterThan(0);
});
