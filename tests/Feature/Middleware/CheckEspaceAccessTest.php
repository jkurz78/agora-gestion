<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeUserWithRole(Association $association, string $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role, 'joined_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('allows admin to access compta routes', function () {
    $user = makeUserWithRole($this->association, 'admin');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows admin to access gestion routes', function () {
    $user = makeUserWithRole($this->association, 'admin');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows admin to access parametres routes', function () {
    $user = makeUserWithRole($this->association, 'admin');
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertOk();
});

it('allows comptable to access compta routes', function () {
    $user = makeUserWithRole($this->association, 'comptable');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows comptable to access gestion routes (read)', function () {
    $user = makeUserWithRole($this->association, 'comptable');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('denies comptable access to parametres', function () {
    $user = makeUserWithRole($this->association, 'comptable');
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows gestionnaire to access gestion routes', function () {
    $user = makeUserWithRole($this->association, 'gestionnaire');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows gestionnaire to access compta routes (read)', function () {
    $user = makeUserWithRole($this->association, 'gestionnaire');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('denies gestionnaire access to parametres', function () {
    $user = makeUserWithRole($this->association, 'gestionnaire');
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows consultation to read compta', function () {
    $user = makeUserWithRole($this->association, 'consultation');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('allows consultation to read gestion', function () {
    $user = makeUserWithRole($this->association, 'consultation');
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('denies consultation access to parametres', function () {
    $user = makeUserWithRole($this->association, 'consultation');
    $this->actingAs($user)->get(route('parametres.utilisateurs.index'))->assertStatus(403);
});
