<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('GET /compta/parametres/categories retourne 200', function () {
    $response = $this->get('/compta/parametres/categories');
    $response->assertStatus(200);
});

test('GET /compta/parametres/sous-categories retourne 200', function () {
    $response = $this->get('/compta/parametres/sous-categories');
    $response->assertStatus(200);
});

test('GET /compta/banques/comptes retourne 200', function () {
    $response = $this->get('/compta/banques/comptes');
    $response->assertStatus(200);
});

test('GET /compta/parametres/utilisateurs retourne 200', function () {
    $response = $this->get('/compta/parametres/utilisateurs');
    $response->assertStatus(200);
});

test('GET /compta/parametres redirige ou retourne 404 (route supprimée)', function () {
    $response = $this->get('/compta/parametres');
    $response->assertStatus(404);
});

test('POST /compta/parametres/categories redirige vers /compta/parametres/categories', function () {
    $response = $this->post('/compta/parametres/categories', [
        'nom' => 'Test Categorie',
        'type' => 'depense',
    ]);
    $response->assertRedirect('/compta/parametres/categories');
});

test('POST /compta/banques/comptes redirige vers /compta/banques/comptes', function () {
    $response = $this->post('/compta/banques/comptes', [
        'nom' => 'Compte Test',
        'solde_initial' => 0,
        'date_solde_initial' => '2025-09-01',
    ]);
    $response->assertRedirect('/compta/banques/comptes');
});

test('POST /compta/parametres/utilisateurs redirige vers /compta/parametres/utilisateurs', function () {
    $response = $this->post('/compta/parametres/utilisateurs', [
        'nom' => 'Test User',
        'email' => 'testuser@example.com',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
    ]);
    $response->assertRedirect('/compta/parametres/utilisateurs');
});

test('GET /compta/parametres/helloasso retourne 200', function () {
    $response = $this->get('/compta/parametres/helloasso');
    $response->assertStatus(200);
});
