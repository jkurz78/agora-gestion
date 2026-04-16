<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to admin role for new users', function () {
    $user = User::factory()->create();
    expect($user->role)->toBe(RoleAssociation::Admin);
});

it('casts role to Role enum', function () {
    $user = User::factory()->create(['role' => 'comptable']);
    expect($user->role)->toBe(RoleAssociation::Comptable);
    expect($user->role)->toBeInstanceOf(RoleAssociation::class);
});

it('includes role in fillable', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);
    expect($user->role)->toBe(RoleAssociation::Consultation);
});
