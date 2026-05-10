<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Livewire\Tiers\Onglets\Adhesion as AdhesionComponent;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Marie Curie',
        'signataire_qualite' => 'Présidente',
    ]);
    TenantContext::boot($this->asso);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->asso->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->actingAs($this->user);
});

/**
 * Crée une adhésion payée + déductible avec ligne cotisation valide.
 */
function creerAdhesionDeductiblePayee(array $tiersOverrides = [], array $adhesionOverrides = []): Adhesion
{
    $tiers = Tiers::factory()->create(array_merge([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'Recu',
        'adresse_ligne1' => '5 avenue de la République',
        'code_postal' => '69001',
        'ville' => 'Lyon',
    ], $tiersOverrides));

    $sousCat = SousCategorie::query()
        ->whereHas('usages', fn ($q) => $q->where('usage', UsageComptable::Cotisation->value))
        ->first()
        ?? SousCategorie::factory()->pourCotisations()->create();

    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
        'date' => now()->subMonths(1),
    ]);

    // Supprimer les lignes auto-créées par les observers
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 75.00,
    ]);

    // Supprimer les adhésions auto-créées par les observers
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    return Adhesion::factory()->create(array_merge([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => fake()->unique()->numberBetween(2020, 2030),
    ], $adhesionOverrides));
}

// ── Cas 1 : adhésion déductible + asso éligible → bouton "Émettre" affiché ──
it('affiche le bouton "Émettre" pour une adhésion déductible avec asso éligible', function () {
    $adhesion = creerAdhesionDeductiblePayee();

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertSee('Émettre');
});

// ── Cas 2 : adhésion non déductible → bouton non affiché ──
it('n\'affiche pas le bouton "Émettre" pour une adhésion non déductible', function () {
    $adhesion = creerAdhesionDeductiblePayee([], ['deductible_fiscal' => false]);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertDontSee('Émettre');
});

// ── Cas 3 : adhésion gratuite → bouton non affiché ──
it('n\'affiche pas le bouton "Émettre" pour une adhésion gratuite', function () {
    $tiers = Tiers::factory()->create();

    // Supprimer les adhésions auto-créées
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_id' => null,
        'deductible_fiscal' => true,
        'exercice' => 2025,
    ]);

    Livewire::test(AdhesionComponent::class, ['tiers' => $tiers])
        ->assertDontSee('Émettre');
});

// ── Cas 4 : asso non éligible → bouton non affiché (même si adhésion déductible) ──
it('n\'affiche pas le bouton "Émettre" si l\'asso n\'est pas éligible', function () {
    // Désactiver l'éligibilité de l'asso
    $this->asso->update(['eligible_recu_fiscal' => false]);

    $adhesion = creerAdhesionDeductiblePayee();

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertDontSee('Émettre');
});

// ── Cas 5 : reçu déjà émis → badge n° affiché, pas de bouton "Émettre" ──
it('affiche le badge n° reçu et pas le bouton "Émettre" quand un reçu est déjà émis', function () {
    $adhesion = creerAdhesionDeductiblePayee();

    // Émettre le reçu via le service
    $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertSee($recu->numero)
        ->assertDontSee('Émettre');
});

// ── Cas 6 : click "Émettre" → reçu créé + redirect vers download ──
it('click sur "Émettre" crée le reçu et redirige vers le téléchargement', function () {
    $adhesion = creerAdhesionDeductiblePayee();

    expect(RecuFiscalEmis::count())->toBe(0);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->call('emettreRecuFiscalAdhesion', $adhesion->id)
        ->assertRedirect();

    expect(RecuFiscalEmis::count())->toBe(1);
    $recu = RecuFiscalEmis::first();
    expect($recu->annule_at)->toBeNull();
});
