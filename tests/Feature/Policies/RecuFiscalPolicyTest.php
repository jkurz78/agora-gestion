<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

it('autorise un user du tenant à télécharger', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => RoleAssociation::Admin->value, 'joined_at' => now()]);
    $recu = RecuFiscalEmis::factory()->create();

    expect($user->can('download', $recu))->toBeTrue();
});

it('refuse un user d\'un autre tenant', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $recu = RecuFiscalEmis::factory()->create();

    TenantContext::boot($asso2);
    $user = User::factory()->create();
    $user->associations()->attach($asso2->id, ['role' => RoleAssociation::Admin->value, 'joined_at' => now()]);

    expect($user->can('download', $recu))->toBeFalse();
});
