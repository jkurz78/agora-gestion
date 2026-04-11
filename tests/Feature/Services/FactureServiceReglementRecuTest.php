<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FactureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(FactureService::class);
    $this->compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
    $this->tiers = Tiers::factory()->create();
    $this->exercice = now()->month >= 9 ? now()->year : now()->year - 1;
});

describe('marquerReglementRecu', function () {
    it('renseigne date_reglement et reference_reglement sur les transactions sélectionnées', function () {
        $facture = Facture::create([
            'date' => now(),
            'statut' => StatutFacture::Validee,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 120.00,
            'saisi_par' => $this->user->id,
            'exercice' => $this->exercice,
        ]);

        $transaction = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'compte_id' => $this->compteCreances->id,
            'montant_total' => 120.00,
            'mode_paiement' => ModePaiement::Cheque,
        ]);

        $facture->transactions()->attach($transaction->id);

        $this->service->marquerReglementRecu(
            $facture,
            [$transaction->id],
            now()->toDateString(),
            'CHQ-98765',
        );

        $transaction->refresh();
        expect($transaction->date_reglement)->not->toBeNull();
        expect($transaction->date_reglement->toDateString())->toBe(now()->toDateString());
        expect($transaction->reference_reglement)->toBe('CHQ-98765');
        expect($transaction->compte_id)->toBe($this->compteCreances->id);
    });

    it('accepte une reference_reglement null', function () {
        $facture = Facture::create([
            'date' => now(),
            'statut' => StatutFacture::Validee,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 50.00,
            'saisi_par' => $this->user->id,
            'exercice' => $this->exercice,
        ]);

        $transaction = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'compte_id' => $this->compteCreances->id,
            'montant_total' => 50.00,
            'mode_paiement' => ModePaiement::Especes,
        ]);

        $facture->transactions()->attach($transaction->id);

        $this->service->marquerReglementRecu($facture, [$transaction->id], now()->toDateString());

        $transaction->refresh();
        expect($transaction->date_reglement)->not->toBeNull();
        expect($transaction->reference_reglement)->toBeNull();
    });

    it('refuse sur une facture brouillon', function () {
        $facture = Facture::create([
            'date' => now(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 100.00,
            'saisi_par' => $this->user->id,
            'exercice' => $this->exercice,
        ]);

        $this->service->marquerReglementRecu($facture, [], now()->toDateString());
    })->throws(RuntimeException::class, 'validée');

    it('refuse si la facture est déjà acquittée', function () {
        $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
        $facture = Facture::create([
            'date' => now(),
            'statut' => StatutFacture::Validee,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 100.00,
            'saisi_par' => $this->user->id,
            'exercice' => $this->exercice,
        ]);

        $transaction = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'compte_id' => $compteReel->id,
            'montant_total' => 100.00,
        ]);

        $facture->transactions()->attach($transaction->id);

        $this->service->marquerReglementRecu($facture, [$transaction->id], now()->toDateString());
    })->throws(RuntimeException::class, 'réglée');

    it('refuse une transaction qui n\'est pas sur un compte système', function () {
        $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
        $facture = Facture::create([
            'date' => now(),
            'statut' => StatutFacture::Validee,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 300.00,
            'saisi_par' => $this->user->id,
            'exercice' => $this->exercice,
        ]);

        $transaction = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'compte_id' => $compteReel->id,
            'montant_total' => 100.00,
        ]);

        $facture->transactions()->attach($transaction->id);

        $this->service->marquerReglementRecu($facture, [$transaction->id], now()->toDateString());
    })->throws(RuntimeException::class, 'compte système');
});
