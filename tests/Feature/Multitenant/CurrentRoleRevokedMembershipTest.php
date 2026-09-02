<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Policies\TiersPolicy;
use App\Tenant\TenantContext;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

/**
 * currentRole() alimente les policies (TiersPolicy, ImmobilisationPolicy,
 * OperationPolicy, ExtournePolicy…) sans jamais repasser par les middlewares
 * ResolveTenant / EnsureTenantAccess : les jobs, les commandes artisan et le
 * callback HelloAsso appellent TenantContext::boot() directement. Ces deux
 * garde-fous web n'existent pas sur ce chemin — c'est currentRole() lui-même
 * qui doit ignorer une adhésion révoquée, exactement comme le font déjà
 * ResolveTenant, EnsureTenantAccess et ForceWizardIfNotCompleted côté web.
 */
it('currentRole rend null pour une adhesion revoquee meme sans passer par les middlewares web', function () {
    $association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => 'admin',
        'joined_at' => now(),
        'revoked_at' => now(),
    ]);

    // Chemin non-HTTP : TenantContext::boot() appelé directement, comme le
    // ferait un job ou une commande artisan — aucun middleware ne tourne ici.
    TenantContext::boot($association);

    expect($user->currentRole())->toBeNull();
});

it('une policy derive un refus pour un membre revoque via currentRole', function () {
    $association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => 'admin',
        'joined_at' => now(),
        'revoked_at' => now(),
    ]);

    TenantContext::boot($association);

    expect((new TiersPolicy)->create($user))->toBeFalse();
});
