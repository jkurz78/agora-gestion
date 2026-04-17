<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\RoleSysteme;
use App\Models\Association;
use App\Models\User;

beforeEach(function () {
    $this->association = Association::factory()->unonboarded()->create();
    $this->admin = User::factory()->create(['role_systeme' => RoleSysteme::User]);
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $this->association->id]);
});

it('redirects an admin with incomplete wizard to /onboarding', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertRedirect('/onboarding');
});

it('does not redirect when wizard_completed_at is set', function () {
    $this->association->update(['wizard_completed_at' => now()]);

    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk();
});

it('does not redirect a non-admin user of the same asso', function () {
    $user = User::factory()->create(['role_systeme' => RoleSysteme::User]);
    $user->associations()->attach($this->association->id, ['role' => RoleAssociation::Comptable->value, 'joined_at' => now()]);
    session(['current_association_id' => $this->association->id]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

it('does not redirect a super-admin', function () {
    $super = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);

    $this->actingAs($super)
        ->get('/super-admin')
        ->assertOk();
});

it('does not loop on /onboarding itself', function () {
    $this->actingAs($this->admin)
        ->get('/onboarding')
        ->assertOk();
});

it('does not redirect on /logout POST', function () {
    $this->actingAs($this->admin)
        ->post('/logout')
        ->assertRedirect('/');
});
