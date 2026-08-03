<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\UsageCompte;
use App\Tenant\TenantContext;

it('casts usage to UsageComptable enum', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $compte = Compte::factory()->numero('754')->create(['association_id' => $asso->id, 'classe' => 7]);

    $link = UsageCompte::create([
        'association_id' => $asso->id,
        'compte_id' => $compte->id,
        'usage' => UsageComptable::Don,
    ]);

    expect($link->usage)->toBe(UsageComptable::Don);
});

it('is tenant-scoped fail-closed', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso1);
    $compte = Compte::factory()->numero('754')->create(['association_id' => $asso1->id, 'classe' => 7]);
    UsageCompte::create([
        'association_id' => $asso1->id, 'compte_id' => $compte->id, 'usage' => UsageComptable::Don,
    ]);
    TenantContext::boot($asso2);

    expect(UsageCompte::count())->toBe(0);
});
