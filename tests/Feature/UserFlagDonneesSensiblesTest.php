<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->admin);
});

afterEach(function () {
    TenantContext::clear();
});

test('user flag peut_voir_donnees_sensibles can be set via update', function (): void {
    $target = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $this->put(route('parametres.utilisateurs.update', $target), [
        'nom' => $target->nom,
        'email' => $target->email,
        'peut_voir_donnees_sensibles' => '1',
    ]);
    $target->refresh();
    expect($target->peut_voir_donnees_sensibles)->toBeTrue();
});

test('user flag peut_voir_donnees_sensibles defaults to false when unchecked', function (): void {
    $target = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->put(route('parametres.utilisateurs.update', $target), [
        'nom' => $target->nom,
        'email' => $target->email,
    ]);
    $target->refresh();
    expect($target->peut_voir_donnees_sensibles)->toBeFalse();
});

test('checkbox visible in utilisateurs page', function (): void {
    $this->get(route('parametres.utilisateurs.index'))
        ->assertOk()
        ->assertSee('Données sensibles');
});
