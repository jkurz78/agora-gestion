<?php

declare(strict_types=1);

use App\Exceptions\RecuFiscalException;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
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

    $sousCat = SousCategorie::factory()->pourCotisations()->create();
    $formule = FormuleAdhesion::factory()->helloasso('mon-form', $tierId)->create([
        'sous_categorie_id' => $sousCat->id,
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
        'sous_categorie_id' => $sousCat->id,
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
    $sousCat = SousCategorie::factory()->pourCotisations()->create();

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create(['tiers_id' => $tiers->id]);

    // Supprimer les lignes auto-créées pour n'en garder qu'une
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
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

it('résout la ligne cotisation en multi-lignes par sous_categorie_id de la formule', function () {
    $sousCatCotisation = SousCategorie::factory()->pourCotisations()->create();
    $sousCatAutre = SousCategorie::factory()->create();

    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sousCatCotisation->id,
        'est_helloasso' => false,
    ]);

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create(['tiers_id' => $tiers->id]);

    // Supprimer les lignes auto-créées
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    $ligneAttendue = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCatCotisation->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCatAutre->id,
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

it('throws générique si aucune ligne ne correspond (cas dégénéré multi-lignes sans formule)', function () {
    $sousCat1 = SousCategorie::factory()->create();
    $sousCat2 = SousCategorie::factory()->create();

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create(['tiers_id' => $tiers->id]);

    // Supprimer les lignes auto-créées
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    // Créer 2 lignes sans correspondance possible (pas de formule)
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat1->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat2->id,
    ]);

    // Adhésion sans formule : impossible de matcher par sous_categorie_id
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
