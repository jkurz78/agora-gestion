<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admin to access compta routes', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('allows admin to access gestion routes', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('allows admin to access parametres routes', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($user)->get(route('compta.parametres.utilisateurs.index'))->assertOk();
});

it('allows comptable to access compta routes', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('allows comptable to access gestion routes (read)', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('denies comptable access to parametres', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $this->actingAs($user)->get(route('compta.parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows gestionnaire to access gestion routes', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('allows gestionnaire to access compta routes (read)', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('denies gestionnaire access to parametres', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $this->actingAs($user)->get(route('gestion.parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows consultation to read compta', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('allows consultation to read gestion', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('denies consultation access to parametres', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $this->actingAs($user)->get(route('compta.parametres.utilisateurs.index'))->assertStatus(403);
});
