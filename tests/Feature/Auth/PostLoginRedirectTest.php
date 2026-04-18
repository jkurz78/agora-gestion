<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;

it('redirects to dashboard if user has 1 association', function () {
    $user = User::factory()->create();
    $asso = Association::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->update(['derniere_association_id' => $asso->id]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    expect(session('current_association_id'))->toBe($asso->id);
});

it('redirects to selector if user has N associations and no derniere', function () {
    $user = User::factory()->create();
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();
    $user->associations()->attach($assoA->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->associations()->attach($assoB->id, ['role' => 'comptable', 'joined_at' => now()]);
    $user->update(['derniere_association_id' => null]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('association-selector'));
});

it('auto-selects derniere if still valid', function () {
    $user = User::factory()->create();
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();
    $user->associations()->attach($assoA->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->associations()->attach($assoB->id, ['role' => 'comptable', 'joined_at' => now()]);
    $user->update(['derniere_association_id' => $assoB->id]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    expect(session('current_association_id'))->toBe($assoB->id);
});
