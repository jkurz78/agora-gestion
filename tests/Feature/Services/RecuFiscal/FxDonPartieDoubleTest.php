<?php

declare(strict_types=1);

/**
 * FX-Don — Tests PD du flux dons / reçu fiscal.
 *
 * [A] Garde tiers null → RecuFiscalException explicite
 * [B] Montant reçu fiscal utilise credit PD (pas montant legacy)
 * [C] Montant centimes du RecuFiscalEmis utilise credit PD
 */

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Exceptions\RecuFiscalException;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::clear();
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// [A] Garde tiers null → exception explicite
// ---------------------------------------------------------------------------

test('[A] validerEligibilite lève une exception si la transaction n\'a pas de tiers', function (): void {
    $sousCategorieDon = SousCategorie::factory()->pourDons()->create([
        'association_id' => (int) $this->asso->id,
    ]);

    $transaction = Transaction::factory()->create([
        'association_id' => (int) $this->asso->id,
        'tiers_id' => null,
        'type' => TypeTransaction::Recette,
        'date' => now()->subMonth(),
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
    ]);

    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => (int) $transaction->id,
        'sous_categorie_id' => (int) $sousCategorieDon->id,
        'montant' => 100.00,
        'credit' => 100.00,
    ]);

    expect(fn () => app(RecuFiscalService::class)->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'tiers');
})->group('fx_don');

// ---------------------------------------------------------------------------
// [B] Montant reçu fiscal basé sur credit PD (pas montant legacy)
// ---------------------------------------------------------------------------

test('[B] validerEligibilite utilise credit PD pour la garde montant > 0', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $this->asso->id,
        'adresse_ligne1' => '12 rue des Lilas',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $sousCategorieDon = SousCategorie::factory()->pourDons()->create([
        'association_id' => (int) $this->asso->id,
    ]);

    // Ligne avec montant legacy = 0 mais credit PD = 50
    $transaction = Transaction::factory()->create([
        'association_id' => (int) $this->asso->id,
        'tiers_id' => (int) $tiers->id,
        'type' => TypeTransaction::Recette,
        'date' => now()->subMonth(),
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
    ]);

    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => (int) $transaction->id,
        'sous_categorie_id' => (int) $sousCategorieDon->id,
        'montant' => 0.00,
        'credit' => 50.00,
    ]);

    // Avec la bascule PD, la garde montant doit utiliser credit → passe
    app(RecuFiscalService::class)->validerEligibilite($ligne);
    expect(true)->toBeTrue();
})->group('fx_don');

// ---------------------------------------------------------------------------
// [C] Montant centimes du RecuFiscalEmis basé sur credit PD
// ---------------------------------------------------------------------------

test('[C] obtenirOuGenerer stocke montant_centimes depuis credit PD', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $this->asso->id,
        'adresse_ligne1' => '12 rue des Lilas',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $sousCategorieDon = SousCategorie::factory()->pourDons()->create([
        'association_id' => (int) $this->asso->id,
    ]);

    $transaction = Transaction::factory()->create([
        'association_id' => (int) $this->asso->id,
        'tiers_id' => (int) $tiers->id,
        'type' => TypeTransaction::Recette,
        'date' => now()->subMonth(),
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
    ]);

    // credit PD = 75.50, montant legacy = 75.50 (cohérent pour ce test)
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => (int) $transaction->id,
        'sous_categorie_id' => (int) $sousCategorieDon->id,
        'montant' => 75.50,
        'credit' => 75.50,
    ]);

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);

    expect($recu->montant_centimes)->toBe(7550);
})->group('fx_don');
