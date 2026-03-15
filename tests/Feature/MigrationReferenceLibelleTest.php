<?php

use App\Models\CompteBancaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('libelle est nullable sur depenses', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    DB::table('depenses')->insert([
        'date'          => '2025-10-01',
        'libelle'       => null,
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => 'REF-LIBELLE-NULL',
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(DB::table('depenses')->where('reference', 'REF-LIBELLE-NULL')->value('libelle'))->toBeNull();
});

it('libelle est nullable sur recettes', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    DB::table('recettes')->insert([
        'date'          => '2025-10-01',
        'libelle'       => null,
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => 'REF-LIBELLE-NULL-R',
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(DB::table('recettes')->where('reference', 'REF-LIBELLE-NULL-R')->value('libelle'))->toBeNull();
});

it('reference est obligatoire (NOT NULL) sur depenses', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    expect(fn () => DB::table('depenses')->insert([
        'date'          => '2025-10-01',
        'libelle'       => 'Test',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => null,
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]))->toThrow(\Exception::class);
});

it('reference est obligatoire (NOT NULL) sur recettes', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    expect(fn () => DB::table('recettes')->insert([
        'date'          => '2025-10-01',
        'libelle'       => 'Test',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => null,
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]))->toThrow(\Exception::class);
});
