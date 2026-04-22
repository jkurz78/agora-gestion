<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * Tests for POST /{slug}/login — enforces email↔association match (Step 5).
 *
 * Scenarios:
 *   1. jean@svs.fr belongs to SVS → POST /svs/login → redirect /dashboard, TenantContext = SVS
 *   2. marie@exemple.fr belongs only to Exemple → POST /svs/login → redirect back with error, not authenticated
 *   3. jean@svs.fr → POST /svs/login with WRONG password → generic error, not authenticated
 *   4. multi@demo.fr belongs to SVS AND Exemple (derniere=Exemple) → POST /svs/login → redirect /dashboard, current_association_id = SVS
 */
beforeEach(function (): void {
    // Clear the global Pest.php-bootstrapped tenant so we control everything here.
    Association::query()->forceDelete();
    TenantContext::clear();

    $this->svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $this->exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    // jean belongs to SVS only
    $this->jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $this->jean->associations()->attach($this->svs->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->jean->update(['derniere_association_id' => $this->svs->id]);

    // marie belongs to Exemple only
    $this->marie = User::factory()->create(['email' => 'marie@exemple.fr']);
    $this->marie->associations()->attach($this->exemple->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->marie->update(['derniere_association_id' => $this->exemple->id]);

    // multi belongs to both, derniere = Exemple
    $this->multi = User::factory()->create(['email' => 'multi@demo.fr']);
    $this->multi->associations()->attach($this->svs->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->multi->associations()->attach($this->exemple->id, ['role' => 'comptable', 'joined_at' => now()]);
    $this->multi->update(['derniere_association_id' => $this->exemple->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

test('scenario 1: user belonging to SVS can login via /svs/login and lands on dashboard in SVS context', function () {
    $response = $this->from('/svs/login')->post('/svs/login', [
        'email' => 'jean@svs.fr',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));
    expect((int) session('current_association_id'))->toBe((int) $this->svs->id);
});

test('scenario 2: user not belonging to SVS is rejected on /svs/login with French error, session not authenticated', function () {
    $response = $this->from('/svs/login')->post('/svs/login', [
        'email' => 'marie@exemple.fr',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect('/svs/login');
    $response->assertSessionHasErrors(['email' => 'Cet email n\'est pas rattaché à l\'association SVS.']);
});

test('scenario 3: wrong password on /svs/login gives generic auth.failed error, session not authenticated', function () {
    $response = $this->from('/svs/login')->post('/svs/login', [
        'email' => 'jean@svs.fr',
        'password' => 'mauvais-mot-de-passe',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
    // Must NOT contain the association-specific rejection message
    $errors = session('errors');
    if ($errors) {
        expect($errors->first('email'))->not->toContain('rattaché à l\'association');
    }
});

test('scenario 4: multi-asso user logging in via /svs/login gets SVS context even if derniere_association_id is Exemple', function () {
    $response = $this->from('/svs/login')->post('/svs/login', [
        'email' => 'multi@demo.fr',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));
    expect((int) session('current_association_id'))->toBe((int) $this->svs->id);
    // derniere_association_id must be updated to SVS as well
    $this->multi->refresh();
    expect($this->multi->derniere_association_id)->toBe($this->svs->id);
});
