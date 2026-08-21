<?php

declare(strict_types=1);

use App\Exceptions\RecuFiscalException;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $this->service = app(RecuFiscalService::class);
});

function invokeResoudre(RecuFiscalService $service, Adhesion $adhesion): TransactionLigne
{
    $reflection = new ReflectionMethod($service, 'resoudreLigneCotisation');
    $reflection->setAccessible(true);

    return $reflection->invoke($service, $adhesion);
}

it('résout la ligne cotisation pour une formule HelloAsso via helloasso_tier_id', function () {
    $tierId = 42;

    $sousCat = Compte::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->helloasso('mon-form', $tierId)->create([
        'compte_id' => $sousCat->id,
    ]);

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
    ]);

    // Supprimer les lignes auto-créées par Transaction::configure()
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    // Créer 2 lignes : l'une avec helloasso_tier_id correspondant, l'autre non
    $ligneAttendue = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'helloasso_tier_id' => $tierId,
        'compte_id' => $sousCat->id,
        'debit' => 0.0,
        'credit' => 50.0,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'helloasso_tier_id' => 99,
    ]);

    $adhesion = Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'formule_adhesion_id' => $formule->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => 2026,
    ]);

    $ligne = invokeResoudre($this->service, $adhesion);

    expect($ligne->id)->toBe($ligneAttendue->id);
});

it('résout la ligne cotisation pour adhésion manuelle avec une seule ligne', function () {
    $sousCat = Compte::factory()->pourCotisations()->create();

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create(['tiers_id' => $tiers->id]);

    // Supprimer les lignes auto-créées pour n'en garder qu'une
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $sousCat->id,
        'debit' => 0.0,
        'credit' => 50.0,
    ]);

    $adhesion = Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'formule_adhesion_id' => null,
        'exercice' => 2026,
    ]);

    $ligneResolue = invokeResoudre($this->service, $adhesion);

    expect($ligneResolue->id)->toBe($ligne->id);
});

it('résout la ligne cotisation en multi-lignes par compte_id de la formule', function () {
    $compteCotisation = Compte::factory()->pourCotisations()->create();
    $compteAutre = Compte::factory()->create();

    $formule = FormuleAdhesion::factory()->create([
        'compte_id' => $compteCotisation->id,
        'est_helloasso' => false,
    ]);

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create(['tiers_id' => $tiers->id]);

    // Supprimer les lignes auto-créées
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    $ligneAttendue = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compteCotisation->id,
        'debit' => 0.0,
        'credit' => 50.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compteAutre->id,
        'debit' => 0.0,
        'credit' => 30.00,
    ]);

    $adhesion = Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'formule_adhesion_id' => $formule->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => 2026,
    ]);

    $ligne = invokeResoudre($this->service, $adhesion);

    expect($ligne->id)->toBe($ligneAttendue->id);
});

it('throws adhesionGratuite si transaction_id est null', function () {
    $adhesion = Adhesion::factory()->create([
        'transaction_id' => null,
        'deductible_fiscal' => true,
        'exercice' => 2026,
    ]);

    expect(fn () => invokeResoudre($this->service, $adhesion))
        ->toThrow(RecuFiscalException::class, 'gratuite');
});

it('résout la ligne parent (option_id IS NULL) dans une transaction avec lignes options (B1)', function () {
    // Post-B1 : transaction HA avec 2 lignes (parent 0€ + option 12€)
    // resoudreLigneCotisation doit retourner la ligne parent (option_id IS NULL)
    $tierId = 42;
    $sousCat = Compte::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->helloasso('mon-form', $tierId)->create([
        'compte_id' => $sousCat->id,
    ]);

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'helloasso_form_slug' => 'mon-form',
    ]);

    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    // Ligne parent (cotisation 0€ — credit symbolique pour XOR)
    $ligneParent = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $sousCat->id,
        'montant' => 0.00,
        'debit' => 0.0,
        'credit' => 0.01,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => null,
        'helloasso_line_key' => 'parent',
        'helloasso_tier_id' => $tierId,
    ]);

    // Ligne option (12€) — même compte
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $sousCat->id,
        'montant' => 12.00,
        'debit' => 0.0,
        'credit' => 12.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => 18596,
        'helloasso_line_key' => 'option:18596',
        'helloasso_tier_id' => null,
    ]);

    $adhesion = Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'formule_adhesion_id' => $formule->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => 2026,
    ]);

    $ligne = invokeResoudre($this->service, $adhesion);

    expect($ligne->id)->toBe($ligneParent->id);
    expect($ligne->helloasso_option_id)->toBeNull();
});

it('throws générique si aucune ligne ne correspond (cas dégénéré multi-lignes sans formule)', function () {
    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create(['tiers_id' => $tiers->id]);

    // Supprimer les lignes auto-créées
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    // Créer 2 lignes sans correspondance possible (pas de formule, pas de compte_id)
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
    ]);

    // Adhésion sans formule : impossible de matcher par compte_id
    $adhesion = Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'formule_adhesion_id' => null,
        'exercice' => 2026,
    ]);

    expect(fn () => invokeResoudre($this->service, $adhesion))
        ->toThrow(RecuFiscalException::class);
});
