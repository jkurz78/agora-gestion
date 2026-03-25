<?php

declare(strict_types=1);

use App\Models\User;

test('user flag peut_voir_donnees_sensibles can be set via update', function (): void {
    $admin = User::factory()->create();
    $target = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $this->actingAs($admin)
        ->put(route('compta.parametres.utilisateurs.update', $target), [
            'nom' => $target->nom,
            'email' => $target->email,
            'peut_voir_donnees_sensibles' => '1',
        ]);
    $target->refresh();
    expect($target->peut_voir_donnees_sensibles)->toBeTrue();
});

test('user flag peut_voir_donnees_sensibles defaults to false when unchecked', function (): void {
    $admin = User::factory()->create();
    $target = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($admin)
        ->put(route('compta.parametres.utilisateurs.update', $target), [
            'nom' => $target->nom,
            'email' => $target->email,
        ]);
    $target->refresh();
    expect($target->peut_voir_donnees_sensibles)->toBeFalse();
});

test('checkbox visible in utilisateurs page', function (): void {
    $admin = User::factory()->create();
    $this->actingAs($admin)
        ->get(route('compta.parametres.utilisateurs.index'))
        ->assertOk()
        ->assertSee('Données sensibles');
});
