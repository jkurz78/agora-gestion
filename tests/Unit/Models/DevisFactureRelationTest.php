<?php

declare(strict_types=1);

use App\Models\Devis;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;

it('Devis::facture() returns null when no facture exists', function (): void {
    $devis = Devis::factory()->accepte()->create();

    expect($devis->facture)->toBeNull();
});

it('Devis::facture() returns the related Facture via devis_id', function (): void {
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

    $devis->refresh();

    expect($devis->facture)->not->toBeNull()
        ->and((int) $devis->facture->id)->toBe((int) $facture->id);
});

it('Devis::aDejaUneFacture() returns false when no facture linked', function (): void {
    $devis = Devis::factory()->accepte()->create();

    expect($devis->aDejaUneFacture())->toBeFalse();
});

it('Devis::aDejaUneFacture() returns true when a facture with devis_id exists', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $devis = Devis::factory()->accepte()->create(['tiers_id' => $tiers->id]);

    Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
        'devis_id' => $devis->id,
    ]);

    expect($devis->aDejaUneFacture())->toBeTrue();
});

it('Devis::aDejaUneFacture() returns false for another devis not linked to that facture', function (): void {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    $devis1 = Devis::factory()->accepte()->create(['tiers_id' => $tiers->id]);
    $devis2 = Devis::factory()->accepte()->create(['tiers_id' => $tiers->id]);

    Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $user->id,
        'exercice' => 2025,
        'devis_id' => $devis1->id,
    ]);

    // devis2 has no facture
    expect($devis2->aDejaUneFacture())->toBeFalse();
});
