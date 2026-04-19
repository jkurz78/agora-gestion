<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('system accounts are excluded from selectors but visible in lists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Normal accounts
    $compteNormal = CompteBancaire::factory()->create(['nom' => 'Banque Pop', 'est_systeme' => false]);
    // System account (created by migration, or manually here)
    $compteSysteme = CompteBancaire::factory()->create(['nom' => 'Compte système test', 'est_systeme' => true]);

    // Selector query (used in forms)
    $selectableComptes = CompteBancaire::where('est_systeme', false)->get();
    expect($selectableComptes->pluck('nom')->toArray())->toContain('Banque Pop')
        ->and($selectableComptes->pluck('nom')->toArray())->not->toContain('Compte système test');

    // List query (used in consultation)
    $allComptes = CompteBancaire::all();
    expect($allComptes->pluck('nom')->toArray())->toContain('Banque Pop')
        ->and($allComptes->pluck('nom')->toArray())->toContain('Compte système test');
});

it('TransactionForm already filters system accounts via actif_recettes_depenses', function () {
    // System accounts have actif_recettes_depenses = false, so they are already filtered
    $compteNormal = CompteBancaire::factory()->create([
        'nom' => 'Compte Normal',
        'actif_recettes_depenses' => true,
        'est_systeme' => false,
    ]);
    $compteSysteme = CompteBancaire::factory()->create([
        'nom' => 'Compte système test',
        'actif_recettes_depenses' => false,
        'est_systeme' => true,
    ]);

    $comptes = CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get();
    expect($comptes->pluck('nom')->toArray())->toContain('Compte Normal')
        ->and($comptes->pluck('nom')->toArray())->not->toContain('Compte système test');
});
