<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::clear();
    // Use the default association from migration so system accounts ('Créances à recevoir') are in scope.
    $this->association = Association::firstOrFail();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->service = app(FactureService::class);
    $this->compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
    $this->tiers = Tiers::factory()->create();
    $this->exercice = now()->month >= 9 ? now()->year : now()->year - 1;
});

afterEach(function () {
    TenantContext::clear();
});

describe('marquerReglementRecu', function () {
    it('met statut_reglement=recu sur les transactions sélectionnées', function () {
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

        $this->service->marquerReglementRecu($facture, [$transaction->id]);

        $transaction->refresh();
        expect($transaction->statut_reglement->value)->toBe('recu');
        expect($transaction->compte_id)->toBe($this->compteCreances->id);
    });

    it('la facture est comptée comme réglée après marquerReglementRecu', function () {
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

        $this->service->marquerReglementRecu($facture, [$transaction->id]);

        $facture->refresh();
        expect($facture->montantRegle())->toBe(50.0);
        expect($facture->isAcquittee())->toBeTrue();
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
            'statut_reglement' => 'recu',
        ]);

        $facture->transactions()->attach($transaction->id);

        $this->service->marquerReglementRecu($facture, [$transaction->id]);
    })->throws(RuntimeException::class, 'réglée');

});
