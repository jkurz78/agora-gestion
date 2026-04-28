<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;

it('Facture::devis() returns the related Devis via devis_id', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $devis = Devis::factory()->accepte()->create(['tiers_id' => $tiers->id]);

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
        'devis_id' => $devis->id,
    ]);

    $facture->refresh();

    expect($facture->devis)->not->toBeNull()
        ->and((int) $facture->devis->id)->toBe((int) $devis->id);
});

it('Facture::devis() returns null when devis_id is null', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
        'devis_id' => null,
    ]);

    expect($facture->devis)->toBeNull();
});

it('Facture cast mode_paiement_prevu returns a ModePaiement enum', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
        'mode_paiement_prevu' => 'virement',
    ]);

    $facture->refresh();

    expect($facture->mode_paiement_prevu)->toBeInstanceOf(ModePaiement::class)
        ->and($facture->mode_paiement_prevu)->toBe(ModePaiement::Virement);
});

it('Facture cast mode_paiement_prevu is null when not set', function (): void {
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

    $facture->refresh();

    expect($facture->mode_paiement_prevu)->toBeNull();
});

it('Facture devis_id is cast to integer', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();
    $devis = Devis::factory()->accepte()->create(['tiers_id' => $tiers->id]);

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
        'devis_id' => $devis->id,
    ]);

    $facture->refresh();

    expect($facture->devis_id)->toBeInt()
        ->and($facture->devis_id)->toBe((int) $devis->id);
});
