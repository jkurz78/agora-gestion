<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Paul Lefebvre',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($this->asso);

    $this->service = app(RecuFiscalService::class);
});

/**
 * Crée une adhésion payée + déductible avec ligne cotisation valide, prête pour l'émission d'un reçu.
 */
function adhesionDeductiblePayee(array $overrides = []): Adhesion
{
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'Annule',
        'adresse_ligne1' => '3 rue de la Paix',
        'code_postal' => '13001',
        'ville' => 'Marseille',
    ]);

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
        'montant' => 50.00,
    ]);

    // Supprimer les adhésions auto-créées par les observers pour éviter la contrainte unique
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    return Adhesion::factory()->create(array_merge([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => fake()->unique()->numberBetween(2020, 2030),
    ], $overrides));
}

it('suppression d\'adhésion avec reçu actif → reçu annulé avec motif "Adhésion supprimée"', function () {
    $adhesion = adhesionDeductiblePayee();

    // Émettre le reçu
    $recu = $this->service->obtenirOuGenererPourAdhesion($adhesion);
    expect($recu->annule_at)->toBeNull();

    // Supprimer l'adhésion — l'observer doit annuler le reçu
    $adhesion->delete();

    $recu->refresh();
    expect($recu->annule_at)->not->toBeNull();
    expect($recu->annule_motif)->toBe('Adhésion supprimée');
});

it('idempotence : suppression d\'adhésion sans reçu actif ne crash pas', function () {
    $adhesion = adhesionDeductiblePayee();

    // Aucun reçu émis — la suppression doit se passer sans crash
    expect(fn () => $adhesion->delete())->not->toThrow(Throwable::class);

    expect(RecuFiscalEmis::count())->toBe(0);
});

it('suppression d\'adhésion gratuite (transaction_id=null) ne crash pas', function () {
    $tiers = Tiers::factory()->create();

    // Supprimer les éventuelles adhésions auto-créées pour ce tiers
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    $adhesion = Adhesion::factory()->create([
        'transaction_id' => null,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => 2099,
    ]);

    // L'observer doit court-circuiter sans crash car transaction_id est null
    expect(fn () => $adhesion->delete())->not->toThrow(Throwable::class);

    expect(RecuFiscalEmis::count())->toBe(0);
});

it('reçu déjà annulé : suppression d\'adhésion ne re-annule pas', function () {
    $adhesion = adhesionDeductiblePayee();

    // Émettre puis annuler le reçu manuellement avant la suppression de l'adhésion
    $recu = $this->service->obtenirOuGenererPourAdhesion($adhesion);
    $this->service->annuler($recu, 'Annulation manuelle de test');

    $recu->refresh();
    $premiereAnnulation = $recu->annule_at;
    $premierMotif = $recu->annule_motif;

    // Supprimer l'adhésion : l'observer ne doit pas re-annuler (reçu déjà annulé)
    expect(fn () => $adhesion->delete())->not->toThrow(Throwable::class);

    $recu->refresh();
    // Le motif et la date d'annulation restent ceux de l'annulation manuelle
    expect($recu->annule_motif)->toBe($premierMotif);
    expect($recu->annule_at->toDateTimeString())->toBe($premiereAnnulation->toDateTimeString());
});
