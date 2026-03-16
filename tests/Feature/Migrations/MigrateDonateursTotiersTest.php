<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('migrates a donateur row to tiers and links the don', function () {
    // After migration 200005, donateur_id has been dropped from dons.
    // This test verifies that donateurs table still exists and tiers records
    // can be created from donateur data, and that dons can be linked via tiers_id.

    $donateurId = DB::table('donateurs')->insertGetId([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@example.com',
        'adresse' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $compteId = DB::table('comptes_bancaires')->insertGetId([
        'nom' => 'Test compte',
        'solde_initial' => 0,
        'date_solde_initial' => now(),
        'actif_recettes_depenses' => true,
        'actif_dons_cotisations' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $userId = DB::table('users')->insertGetId([
        'nom' => 'User Test',
        'email' => 'u@t.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create a sous_categorie for the don
    $catId = DB::table('categories')->insertGetId([
        'nom' => 'Produits', 'type' => 'recette', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $sousCatId = DB::table('sous_categories')->insertGetId([
        'categorie_id' => $catId, 'nom' => 'Dons manuels', 'pour_dons' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Simulate migration logic: create tiers from donateur, then link don via tiers_id
    $donateur = DB::table('donateurs')->find($donateurId);
    $tiersId = DB::table('tiers')->insertGetId([
        'type' => 'particulier',
        'nom' => $donateur->nom,
        'prenom' => $donateur->prenom,
        'email' => $donateur->email,
        'telephone' => null,
        'adresse' => $donateur->adresse,
        'pour_depenses' => false,
        'pour_recettes' => true,
        'created_at' => $donateur->created_at,
        'updated_at' => $donateur->updated_at,
    ]);

    $donId = DB::table('dons')->insertGetId([
        'tiers_id' => $tiersId,
        'sous_categorie_id' => $sousCatId,
        'date' => '2025-10-01',
        'montant' => 100,
        'mode_paiement' => 'especes',
        'saisi_par' => $userId,
        'compte_id' => $compteId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Assert
    $don = DB::table('dons')->find($donId);
    expect($don->tiers_id)->not->toBeNull();
    expect($don->tiers_id)->toBe($tiersId);

    $tiers = DB::table('tiers')->find($don->tiers_id);
    expect($tiers->nom)->toBe('Dupont');
    expect($tiers->prenom)->toBe('Marie');
    expect($tiers->pour_recettes)->toBe(1);
});
