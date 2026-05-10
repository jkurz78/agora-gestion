<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Exceptions\RecuFiscalException;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($this->asso);

    $this->service = app(RecuFiscalService::class);
});

/**
 * Crée une adhésion payée avec une ligne cotisation valide.
 * Chaque appel crée un tiers unique pour éviter les collisions de contrainte unique.
 *
 * @param  array<string,mixed>  $adhesionOverrides
 */
function adhesionCotisationValide(array $adhesionOverrides = []): Adhesion
{
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'Sophie',
        'adresse_ligne1' => '10 rue de la Paix',
        'code_postal' => '75002',
        'ville' => 'Paris',
    ]);

    $sousCat = SousCategorie::query()
        ->whereHas('usages', fn ($q) => $q->where('usage', UsageComptable::Cotisation->value))
        ->first()
        ?? SousCategorie::factory()->pourCotisations()->create();

    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    // Supprimer les lignes auto-créées par la factory Transaction::configure()
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
    ]);

    return Adhesion::factory()->create(array_merge([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => fake()->unique()->numberBetween(2020, 2030),
    ], $adhesionOverrides));
}

it('throws adhesionNonDeductible si deductible_fiscal est false', function () {
    $adhesion = adhesionCotisationValide(['deductible_fiscal' => false]);

    expect(fn () => $this->service->validerEligibiliteAdhesion($adhesion))
        ->toThrow(RecuFiscalException::class, 'déductible');
});

it('throws adhesionGratuite si transaction_id est null (vérifié avant tout autre check)', function () {
    $adhesion = Adhesion::factory()->create([
        'transaction_id' => null,
        'deductible_fiscal' => false, // peu importe, gratuite est checked en premier
        'exercice' => 2099,
    ]);

    expect(fn () => $this->service->validerEligibiliteAdhesion($adhesion))
        ->toThrow(RecuFiscalException::class, 'gratuite');
});

it('délègue à validerEligibilite pour les checks tiers/asso/encaissement sur adhésion OK', function () {
    $adhesion = adhesionCotisationValide();

    // Ne doit pas lever d'exception sur une adhésion valide
    $this->service->validerEligibiliteAdhesion($adhesion);

    expect(true)->toBeTrue();
});

it('throws de validerEligibilite si asso non éligible (délégation)', function () {
    $assoNonEligible = Association::factory()->create([
        'eligible_recu_fiscal' => false,
        'signataire_nom' => 'Jean',
        'signataire_qualite' => 'Pres',
    ]);
    TenantContext::boot($assoNonEligible);
    $service = app(RecuFiscalService::class);

    $adhesion = adhesionCotisationValide();

    expect(fn () => $service->validerEligibiliteAdhesion($adhesion))
        ->toThrow(RecuFiscalException::class, 'éligible');
});
