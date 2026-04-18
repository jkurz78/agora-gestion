<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;

it('POST /switch-association changes current asso and redirects', function () {
    $user = User::factory()->create();
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();
    $user->associations()->attach($assoA->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->associations()->attach($assoB->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->update(['derniere_association_id' => $assoA->id]);

    $this->actingAs($user);
    session(['current_association_id' => $assoA->id]);

    $this->post(route('switch-association'), ['association_id' => $assoB->id])
        ->assertRedirect(route('dashboard'));

    expect(session('current_association_id'))->toBe($assoB->id)
        ->and($user->fresh()->derniere_association_id)->toBe($assoB->id);
});

it('switch denies access to association user does not belong to', function () {
    $user = User::factory()->create();
    $asso = Association::factory()->create();
    $foreign = Association::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    $this->actingAs($user);

    $this->post(route('switch-association'), ['association_id' => $foreign->id])
        ->assertStatus(403);
});
