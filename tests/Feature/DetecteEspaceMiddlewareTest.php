<?php

declare(strict_types=1);

use App\Models\User;

test('dashboard loads at /dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

test('root redirects to dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/dashboard');
});

test('legacy /compta/dashboard redirects 301 to /dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/compta/dashboard')
        ->assertRedirect('/dashboard')
        ->assertStatus(301);
});

test('legacy /gestion/dashboard redirects 301 to /dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/dashboard')
        ->assertRedirect('/dashboard')
        ->assertStatus(301);
});

test('legacy /transactions redirects 301 to /comptabilite/transactions', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/transactions')
        ->assertRedirect('/comptabilite/transactions')
        ->assertStatus(301);
});

test('legacy /membres redirects 301 to /tiers/adherents', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/membres')
        ->assertRedirect('/tiers/adherents')
        ->assertStatus(301);
});

test('parametres accessible at /parametres/', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/parametres/association')->assertOk();
});
