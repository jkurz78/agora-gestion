<?php

use App\Models\CompteBancaire;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can store a compte bancaire', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Compte Courant',
            'iban' => 'FR7630006000011234567890189',
            'solde_initial' => 1500.50,
            'date_solde_initial' => '2024-01-01',
        ])
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseHas('comptes_bancaires', [
        'nom' => 'Compte Courant',
        'iban' => 'FR7630006000011234567890189',
    ]);
});

it('validates required fields when storing a compte bancaire', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [])
        ->assertSessionHasErrors(['nom', 'solde_initial', 'date_solde_initial']);
});

it('validates nom max length for compte bancaire', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => str_repeat('a', 151),
            'solde_initial' => 0,
            'date_solde_initial' => '2024-01-01',
        ])
        ->assertSessionHasErrors(['nom']);
});

it('validates iban max length', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Test',
            'iban' => str_repeat('A', 35),
            'solde_initial' => 0,
            'date_solde_initial' => '2024-01-01',
        ])
        ->assertSessionHasErrors(['iban']);
});

it('validates solde_initial is numeric', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Test',
            'solde_initial' => 'pas un nombre',
            'date_solde_initial' => '2024-01-01',
        ])
        ->assertSessionHasErrors(['solde_initial']);
});

it('validates date_solde_initial is a date', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Test',
            'solde_initial' => 100,
            'date_solde_initial' => 'pas-une-date',
        ])
        ->assertSessionHasErrors(['date_solde_initial']);
});

it('can store a compte bancaire without iban', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Caisse',
            'solde_initial' => 0,
            'date_solde_initial' => '2024-01-01',
        ])
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseHas('comptes_bancaires', [
        'nom' => 'Caisse',
        'iban' => null,
    ]);
});

it('can update a compte bancaire', function () {
    $compte = CompteBancaire::factory()->create();

    $this->actingAs($this->user)
        ->put(route('parametres.comptes-bancaires.update', $compte), [
            'nom' => 'Nom modifié',
            'iban' => 'FR7630006000011234567890189',
            'solde_initial' => 2000,
            'date_solde_initial' => '2024-06-01',
        ])
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseHas('comptes_bancaires', [
        'id' => $compte->id,
        'nom' => 'Nom modifié',
    ]);
});

it('can destroy a compte bancaire', function () {
    $compte = CompteBancaire::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('parametres.comptes-bancaires.destroy', $compte))
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseMissing('comptes_bancaires', ['id' => $compte->id]);
});
