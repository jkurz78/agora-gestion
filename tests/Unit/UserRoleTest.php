<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to admin role for new users', function () {
    $user = User::factory()->create();
    expect($user->role)->toBe(Role::Admin);
});

it('casts role to Role enum', function () {
    $user = User::factory()->create(['role' => 'comptable']);
    expect($user->role)->toBe(Role::Comptable);
    expect($user->role)->toBeInstanceOf(Role::class);
});

it('includes role in fillable', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    expect($user->role)->toBe(Role::Consultation);
});
