<?php

declare(strict_types=1);

/**
 * Tâche 15 (709A) — reçu fiscal net de remise.
 *
 * Le chantier 709A fait porter le montant BRUT à la ligne parente d'une
 * adhésion HelloAsso (credit = plein tarif), et non plus le net comme avant
 * cette réforme. Sans correction, RecuFiscalService::montantRecu() lisait
 * ce brut tel quel : une adhésion entièrement offerte (code promo à 100%)
 * obtenait un credit > 0 et franchissait la garde "montant > 0" de
 * validerEligibilite() — un reçu fiscal aurait été émis pour de l'argent
 * jamais versé. Avant 709A, cette même garde se déclenchait par accident :
 * la ligne parente portait déjà le net (0€), donc credit = 0 — un
 * garde-fou fortuit (cas Georges SAND, commit 2dada27d), pas une protection
 * voulue par construction.
 *
 * Ces tests couvrent les 3 cas : gratuité totale (aucun reçu), remise
 * partielle (reçu au net), et adhésion manuelle sans HelloAsso (non
 * régression). Ils couvrent aussi la résolution de la ligne cotisation :
 * la ligne technique de remise ne doit jamais être prise pour elle.
 */

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Exceptions\RecuFiscalException;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\RecuFiscalEmis;
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
 * Crée un tiers éligible (adresse complète, requise par validerEligibilite).
 */
function tiersEligible(): Tiers
{
    return Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'Test',
        'adresse_ligne1' => '5 avenue de la République',
        'code_postal' => '69001',
        'ville' => 'Lyon',
    ]);
}

/**
 * Crée une adhésion HelloAsso payée + déductible dont la transaction porte
 * deux lignes : la cotisation au BRUT (helloasso_line_key = 'parent') et,
 * si $remise > 0, la ligne technique de remise (helloasso_line_key =
 * 'discount') rattachée au même helloasso_item_id — même construction que
 * celle produite par HelloAssoSyncService::synchroniser() (voir
 * tests/Feature/HelloAsso/SyncRemiseTest.php).
 */
function adhesionHelloAssoAvecRemise(float $brut, float $remise): Adhesion
{
    $tiers = tiersEligible();

    $compteCotisation = Compte::query()
        ->forUsage(UsageComptable::Cotisation)
        ->first()
        ?? Compte::factory()->pourCotisations()->create();

    // Compte de contrepartie des gratuités (709A) — la migration
    // 2026_08_20_100000 le crée pour toute association préexistante ;
    // firstOrCreate couvre aussi l'association créée par ce test, jamais
    // create() pour ne pas dupliquer un 709A déjà posé.
    $compteGratuite = Compte::firstOrCreate(
        [
            'association_id' => TenantContext::currentId(),
            'numero_pcg' => '709A',
        ],
        [
            'intitule' => 'Gratuités accordées',
            'classe' => 7,
            'actif' => true,
        ],
    );

    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
        'date' => now()->subMonths(1),
    ]);

    // Supprimer les lignes auto-créées par Transaction::configure()
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    $itemId = fake()->unique()->numberBetween(100000, 999999);

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compteCotisation->id,
        'montant' => $brut,
        'debit' => 0,
        'credit' => $brut,
        'helloasso_item_id' => $itemId,
        'helloasso_line_key' => 'parent',
    ]);

    if ($remise > 0) {
        TransactionLigne::factory()->create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compteGratuite->id,
            'montant' => $remise,
            'debit' => $remise,
            'credit' => 0,
            'helloasso_item_id' => $itemId,
            'helloasso_line_key' => 'discount',
            'helloasso_discount_code' => 'PROMO2026',
        ]);
    }

    // L'observer TransactionLigne peut avoir auto-créé une adhésion à la
    // création de la ligne parente. On la supprime avant de créer
    // l'adhésion de test pour éviter la violation de contrainte unique
    // (association_id, tiers_id, exercice) — même précaution que
    // ObtenirOuGenererPourAdhesionTest::adhesionPayeeDeductible().
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    return Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => fake()->unique()->numberBetween(2020, 2030),
    ]);
}

it("n'émet aucun reçu sur une adhésion entièrement offerte (remise = brut)", function () {
    // Cotisation à 35€, remise de 35€ : net 0€. C'est exactement le cas
    // Georges SAND, mais reconstruit sous 709A où la ligne parente porte
    // désormais le brut (credit = 35), pas le net.
    $adhesion = adhesionHelloAssoAvecRemise(35.00, 35.00);

    expect(fn () => $this->service->obtenirOuGenererPourAdhesion($adhesion))
        ->toThrow(RecuFiscalException::class);

    // Le point qui compte vraiment : aucun document fiscal n'a été créé,
    // pas seulement qu'une exception a été levée.
    expect(RecuFiscalEmis::count())->toBe(0);
});

it('émet un reçu au net versé sur une remise partielle', function () {
    // Cotisation à 35€, remise de 20€ : seuls 15€ ont réellement été versés.
    $adhesion = adhesionHelloAssoAvecRemise(35.00, 20.00);

    $recu = $this->service->obtenirOuGenererPourAdhesion($adhesion);

    expect($recu->montant_centimes)->toBe(1500);
});

it('ne retient jamais la ligne de remise comme ligne référencée par le reçu', function () {
    $adhesion = adhesionHelloAssoAvecRemise(35.00, 20.00);

    $recu = $this->service->obtenirOuGenererPourAdhesion($adhesion);

    expect($recu->transactionLigne->helloasso_line_key)->toBe('parent');
});

it('émet un reçu au montant plein pour une adhésion manuelle (non régression)', function () {
    // Adhésion saisie à la main : aucune colonne helloasso_*, donc aucune
    // déduction — le comportement d'avant la Tâche 15 doit être inchangé.
    $tiers = tiersEligible();

    $compteCotisation = Compte::query()
        ->forUsage(UsageComptable::Cotisation)
        ->first()
        ?? Compte::factory()->pourCotisations()->create();

    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
        'date' => now()->subMonths(1),
    ]);

    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compteCotisation->id,
        'montant' => 75.00,
        'debit' => 0,
        'credit' => 75.00,
    ]);

    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    $adhesion = Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => fake()->unique()->numberBetween(2020, 2030),
    ]);

    $recu = $this->service->obtenirOuGenererPourAdhesion($adhesion);

    expect($recu->montant_centimes)->toBe(7500);
});
