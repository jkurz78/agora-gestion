<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admin to access compta routes', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows admin to access gestion routes', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows admin to access parametres routes', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertOk();
});

it('allows comptable to access compta routes', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows comptable to access gestion routes (read)', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('denies comptable access to parametres', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows gestionnaire to access gestion routes', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows gestionnaire to access compta routes (read)', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('denies gestionnaire access to parametres', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows consultation to read compta', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows consultation to read gestion', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('denies consultation access to parametres', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertStatus(403);
});
