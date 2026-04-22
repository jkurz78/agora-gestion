<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * Regression test — UserController::index must only return users belonging
 * to the current tenant (via the association_user pivot), not all users in
 * the database.
 *
 * Bug pre-existed the slug-login slice: the query was a bare User::orderBy()->get()
 * with no association filter.
 */
beforeEach(function (): void {
    $this->svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $this->exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    // alice belongs to SVS only
    $this->alice = User::factory()->create(['email' => 'alice@svs.fr', 'nom' => 'Alice']);
    $this->alice->associations()->attach($this->svs->id, [
        'role' => RoleAssociation::Consultation->value,
        'joined_at' => now(),
    ]);

    // bob belongs to Exemple only
    $this->bob = User::factory()->create(['email' => 'bob@exemple.fr', 'nom' => 'Bob']);
    $this->bob->associations()->attach($this->exemple->id, [
        'role' => RoleAssociation::Consultation->value,
        'joined_at' => now(),
    ]);

    // admin belongs to SVS as admin, acting user for the request
    $this->admin = User::factory()->create(['email' => 'admin@svs.fr', 'nom' => 'Admin SVS']);
    $this->admin->associations()->attach($this->svs->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->admin->update(['derniere_association_id' => $this->svs->id]);

    TenantContext::boot($this->svs);
});

afterEach(function (): void {
    TenantContext::clear();
});

test('GIVEN users in 2 assos WHEN GET /utilisateurs as SVS admin THEN only SVS users are listed', function (): void {
    $response = $this->actingAs($this->admin)
        ->withSession(['current_association_id' => $this->svs->id])
        ->get(route('parametres.utilisateurs.index'));

    $response->assertStatus(200);
    $response->assertSee('alice@svs.fr');
    $response->assertSee('admin@svs.fr');
    $response->assertDontSee('bob@exemple.fr');
});

test('GIVEN bob is revoked from SVS WHEN GET /utilisateurs THEN bob is not shown', function (): void {
    // Attach bob to SVS but with a revoked_at timestamp.
    $this->bob->associations()->attach($this->svs->id, [
        'role' => RoleAssociation::Consultation->value,
        'joined_at' => now()->subDays(10),
        'revoked_at' => now()->subDays(1),
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_association_id' => $this->svs->id])
        ->get(route('parametres.utilisateurs.index'));

    $response->assertStatus(200);
    $response->assertDontSee('bob@exemple.fr');
});

test('GIVEN alice is only in SVS WHEN Exemple admin GETs /utilisateurs THEN alice is not shown', function (): void {
    $exempleAdmin = User::factory()->create(['email' => 'chef@exemple.fr', 'nom' => 'Chef Exemple']);
    $exempleAdmin->associations()->attach($this->exemple->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $exempleAdmin->update(['derniere_association_id' => $this->exemple->id]);

    $response = $this->actingAs($exempleAdmin)
        ->withSession(['current_association_id' => $this->exemple->id])
        ->get(route('parametres.utilisateurs.index'));

    $response->assertStatus(200);
    $response->assertDontSee('alice@svs.fr');
    $response->assertDontSee('admin@svs.fr');
    $response->assertSee('chef@exemple.fr');
    $response->assertSee('bob@exemple.fr');
});
