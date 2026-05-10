<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Exceptions\RecuFiscalException;
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
        'signataire_nom' => 'Marie Curie',
        'signataire_qualite' => 'Présidente',
    ]);
    TenantContext::boot($this->asso);

    $this->service = app(RecuFiscalService::class);
});

/**
 * Crée une adhésion payée + déductible avec ligne cotisation valide.
 *
 * @param  array<string,mixed>  $adhesionOverrides
 */
function adhesionPayeeDeductible(array $adhesionOverrides = []): Adhesion
{
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'Test',
        'adresse_ligne1' => '5 avenue de la République',
        'code_postal' => '69001',
        'ville' => 'Lyon',
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

    // Supprimer les lignes auto-créées
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 75.00,
    ]);

    return Adhesion::factory()->create(array_merge([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => fake()->unique()->numberBetween(2020, 2030),
    ], $adhesionOverrides));
}

it('génère un reçu actif sur adhésion payée + déductible + asso éligible', function () {
    $adhesion = adhesionPayeeDeductible();

    $recu = $this->service->obtenirOuGenererPourAdhesion($adhesion);

    expect($recu)->toBeInstanceOf(RecuFiscalEmis::class);
    expect($recu->annule_at)->toBeNull();
    expect($recu->numero)->toStartWith((string) now()->year.'-');
    expect($recu->montant_centimes)->toBe(7500); // 75.00€ en centimes
    expect(Storage::disk('local')->exists($recu->pdfFullPath()))->toBeTrue();
});

it('est idempotent : 2 appels successifs retournent le même RecuFiscalEmis', function () {
    $adhesion = adhesionPayeeDeductible();

    $recu1 = $this->service->obtenirOuGenererPourAdhesion($adhesion);
    $recu2 = $this->service->obtenirOuGenererPourAdhesion($adhesion);

    expect($recu2->id)->toBe($recu1->id);
    expect($recu2->numero)->toBe($recu1->numero);
    expect(RecuFiscalEmis::count())->toBe(1);
});

it('throws adhesionGratuite si transaction_id est null', function () {
    $adhesion = Adhesion::factory()->create([
        'transaction_id' => null,
        'deductible_fiscal' => true,
        'exercice' => 2099,
    ]);

    expect(fn () => $this->service->obtenirOuGenererPourAdhesion($adhesion))
        ->toThrow(RecuFiscalException::class, 'gratuite');
});

it('throws adhesionNonDeductible si deductible_fiscal est false', function () {
    $adhesion = adhesionPayeeDeductible(['deductible_fiscal' => false]);

    expect(fn () => $this->service->obtenirOuGenererPourAdhesion($adhesion))
        ->toThrow(RecuFiscalException::class, 'déductible');
});

it('numérotation partagée don+cotisation : séquence continue', function () {
    // Émettre 1 reçu don via obtenirOuGenerer(TransactionLigne)
    $ligne = $this->ligneDonValide();
    $recuDon = $this->service->obtenirOuGenerer($ligne);

    // Émettre 1 reçu cotisation
    $adhesion = adhesionPayeeDeductible();
    $recuCotisation = $this->service->obtenirOuGenererPourAdhesion($adhesion);

    $annee = (string) now()->year;
    expect($recuDon->numero)->toBe("{$annee}-0001");
    expect($recuCotisation->numero)->toBe("{$annee}-0002");
    expect(RecuFiscalEmis::count())->toBe(2);
});
