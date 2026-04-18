<?php

declare(strict_types=1);

use App\Livewire\Auth\AssociationSelector;
use App\Models\Association;
use App\Models\User;
use Livewire\Livewire;

it('displays associations the user belongs to', function () {
    $user = User::factory()->create();
    $assoA = Association::factory()->create(['nom' => 'Asso A']);
    $assoB = Association::factory()->create(['nom' => 'Asso B']);
    $user->associations()->attach($assoA->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->associations()->attach($assoB->id, ['role' => 'comptable', 'joined_at' => now()]);

    $this->actingAs($user);

    Livewire::test(AssociationSelector::class)
        ->assertSee('Asso A')
        ->assertSee('Asso B');
});

it('selecting an association sets session and redirects to dashboard', function () {
    $user = User::factory()->create();
    $asso = Association::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    $this->actingAs($user);

    Livewire::test(AssociationSelector::class)
        ->call('select', $asso->id)
        ->assertRedirect(route('dashboard'));

    expect(session('current_association_id'))->toBe($asso->id)
        ->and($user->fresh()->derniere_association_id)->toBe($asso->id);
});

it('403 if user selects association they do not belong to', function () {
    $user = User::factory()->create();
    $foreignAsso = Association::factory()->create();

    $this->actingAs($user);

    Livewire::test(AssociationSelector::class)
        ->call('select', $foreignAsso->id)
        ->assertStatus(403);
});
