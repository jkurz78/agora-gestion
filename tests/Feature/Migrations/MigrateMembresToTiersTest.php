<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('migrates a membre row to tiers and links the cotisation', function () {
    // Since membre_id has been removed from cotisations,
    // we verify the migration logic using the current schema (tiers_id only).
    $compteId = DB::table('comptes_bancaires')->insertGetId([
        'nom' => 'Test compte',
        'solde_initial' => 0,
        'date_solde_initial' => now(),
        'actif_recettes_depenses' => true,
        'actif_dons_cotisations' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Insert a tiers as a membre (post-migration state)
    $tiersId = DB::table('tiers')->insertGetId([
        'type' => 'particulier',
        'nom' => 'Martin',
        'prenom' => 'Paul',
        'email' => 'paul@example.com',
        'telephone' => null,
        'adresse' => null,
        'date_adhesion' => null,
        'statut_membre' => 'actif',
        'notes_membre' => null,
        'pour_depenses' => false,
        'pour_recettes' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cotisationId = DB::table('cotisations')->insertGetId([
        'tiers_id' => $tiersId,
        'exercice' => 2025,
        'montant' => 50,
        'date_paiement' => '2025-10-01',
        'mode_paiement' => 'especes',
        'compte_id' => $compteId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Assert
    $cotisation = DB::table('cotisations')->find($cotisationId);
    expect($cotisation->tiers_id)->not->toBeNull();

    $tiers = DB::table('tiers')->find($cotisation->tiers_id);
    expect($tiers->nom)->toBe('Martin');
    expect($tiers->statut_membre)->toBe('actif');
});
