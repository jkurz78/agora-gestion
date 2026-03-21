<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('GET /parametres/categories retourne 200', function () {
    $response = $this->get('/parametres/categories');
    $response->assertStatus(200);
});

test('GET /parametres/sous-categories retourne 200', function () {
    $response = $this->get('/parametres/sous-categories');
    $response->assertStatus(200);
});

test('GET /parametres/comptes-bancaires retourne 200', function () {
    $response = $this->get('/parametres/comptes-bancaires');
    $response->assertStatus(200);
});

test('GET /parametres/utilisateurs retourne 200', function () {
    $response = $this->get('/parametres/utilisateurs');
    $response->assertStatus(200);
});

test('GET /parametres redirige ou retourne 404 (route supprimée)', function () {
    $response = $this->get('/parametres');
    $response->assertStatus(404);
});

test('POST /parametres/categories redirige vers /parametres/categories', function () {
    $response = $this->post('/parametres/categories', [
        'nom' => 'Test Categorie',
        'type' => 'depense',
    ]);
    $response->assertRedirect('/parametres/categories');
});

test('POST /parametres/comptes-bancaires redirige vers /parametres/comptes-bancaires', function () {
    $response = $this->post('/parametres/comptes-bancaires', [
        'nom' => 'Compte Test',
        'solde_initial' => 0,
        'date_solde_initial' => '2025-09-01',
    ]);
    $response->assertRedirect('/parametres/comptes-bancaires');
});

test('POST /parametres/utilisateurs redirige vers /parametres/utilisateurs', function () {
    $response = $this->post('/parametres/utilisateurs', [
        'nom' => 'Test User',
        'email' => 'testuser@example.com',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
    ]);
    $response->assertRedirect('/parametres/utilisateurs');
});

test('GET /parametres/helloasso retourne 200', function () {
    $response = $this->get('/parametres/helloasso');
    $response->assertStatus(200);
});
