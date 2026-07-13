<?php

declare(strict_types=1);

use App\Enums\TypeLigneFacture;
use App\Models\Compte;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\User;

it('FactureLigne::compte() returns the related Compte', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compte = Compte::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Mission audit',
        'prix_unitaire' => 800.00,
        'quantite' => 3.000,
        'montant' => 2400.00,
        'ordre' => 1,
        'compte_id' => $compte->id,
    ]);

    $ligne->refresh();

    expect($ligne->compte)->not->toBeNull()
        ->and((int) $ligne->compte->id)->toBe((int) $compte->id);
});

it('FactureLigne::operation() returns the related Operation', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $operation = Operation::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Prestation',
        'prix_unitaire' => 100.00,
        'quantite' => 1.000,
        'montant' => 100.00,
        'ordre' => 1,
        'operation_id' => $operation->id,
    ]);

    $ligne->refresh();

    expect($ligne->operation)->not->toBeNull()
        ->and((int) $ligne->operation->id)->toBe((int) $operation->id);
});

it('FactureLigne decimal casts for prix_unitaire and quantite', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Ligne test',
        'prix_unitaire' => 123.45,
        'quantite' => 2.500,
        'montant' => 308.63,
        'ordre' => 1,
    ]);

    $ligne->refresh();

    // decimal:2 → stored as string with 2 decimal places
    expect($ligne->prix_unitaire)->toBe('123.45')
        // decimal:3 → stored as string with 3 decimal places
        ->and($ligne->quantite)->toBe('2.500');
});

it('FactureLigne integer casts for compte_id and operation_id', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compte = Compte::factory()->create();
    $operation = Operation::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Test casts',
        'prix_unitaire' => 50.00,
        'quantite' => 1.000,
        'montant' => 50.00,
        'ordre' => 1,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'seance' => 42,
    ]);

    $ligne->refresh();

    expect($ligne->compte_id)->toBeInt()
        ->and($ligne->compte_id)->toBe((int) $compte->id)
        ->and($ligne->operation_id)->toBeInt()
        ->and($ligne->operation_id)->toBe((int) $operation->id)
        ->and($ligne->seance)->toBeInt()
        ->and($ligne->seance)->toBe(42);
});

it('FactureLigne new fields are accepted via create()', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $compte = Compte::factory()->create();
    $operation = Operation::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
    ]);

    $ligne = FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Mission complète',
        'prix_unitaire' => 200.00,
        'quantite' => 5.000,
        'montant' => 1000.00,
        'ordre' => 1,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'seance' => 7,
    ]);

    expect($ligne->exists)->toBeTrue()
        ->and($ligne->libelle)->toBe('Mission complète')
        ->and((int) $ligne->compte_id)->toBe((int) $compte->id)
        ->and((int) $ligne->operation_id)->toBe((int) $operation->id)
        ->and((int) $ligne->seance)->toBe(7);
});
