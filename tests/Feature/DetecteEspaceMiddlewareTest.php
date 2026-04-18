<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

test('dashboard loads at /dashboard', function (): void {
    $this->get('/dashboard')
        ->assertOk();
});

test('root redirects to dashboard', function (): void {
    $this->get('/')
        ->assertRedirect('/dashboard');
});

test('legacy /compta/dashboard redirects 301 to /dashboard', function (): void {
    $this->get('/compta/dashboard')
        ->assertRedirect('/dashboard')
        ->assertStatus(301);
});

test('legacy /gestion/dashboard redirects 301 to /dashboard', function (): void {
    $this->get('/gestion/dashboard')
        ->assertRedirect('/dashboard')
        ->assertStatus(301);
});

test('legacy /transactions redirects 301 to /comptabilite/transactions', function (): void {
    $this->get('/transactions')
        ->assertRedirect('/comptabilite/transactions')
        ->assertStatus(301);
});

test('legacy /membres redirects 301 to /tiers/adherents', function (): void {
    $this->get('/membres')
        ->assertRedirect('/tiers/adherents')
        ->assertStatus(301);
});

test('parametres accessible at /parametres/', function (): void {
    $this->get('/parametres/association')->assertOk();
});
