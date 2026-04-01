<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\FactureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->service = app(FactureService::class);
    $this->compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
    $this->compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
});

function createFactureValideeAvecCreance(
    object $testContext,
    float $montant = 100.00,
): Facture {
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => '2025-11-15',
        'libelle' => 'Créance test',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Virement,
        'compte_id' => $testContext->compteCreances->id,
        'tiers_id' => $testContext->tiers->id,
        'saisi_par' => $testContext->user->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => SousCategorie::factory()->create()->id,
        'montant' => $montant,
    ]);

    $facture = $testContext->service->creer($testContext->tiers->id);
    $testContext->service->ajouterTransactions($facture, [$transaction->id]);
    $testContext->service->valider($facture);
    $facture->refresh();

    return $facture;
}

describe('encaisser()', function () {
    it('moves transactions from system account to real account', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);

        $transaction = Transaction::find($transactionIds[0]);
        expect($transaction->compte_id)->toBe($this->compteReel->id);
    });

    it('makes facture acquittée after encaissement', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);

        $facture->refresh();
        expect($facture->isAcquittee())->toBeTrue();
    });

    it('rejects encaissement on brouillon facture', function () {
        $facture = $this->service->creer($this->tiers->id);

        $this->service->encaisser($facture, [], $this->compteReel->id);
    })->throws(RuntimeException::class, 'Seule une facture validée peut être encaissée.');

    it('rejects encaissement on already acquittée facture', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);
        $facture->refresh();

        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);
    })->throws(RuntimeException::class, 'Cette facture est déjà intégralement réglée.');

    it('rejects encaissement to a system account', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        $this->service->encaisser($facture, $transactionIds, $this->compteCreances->id);
    })->throws(RuntimeException::class, 'Le compte de destination doit être un compte bancaire réel.');

    it('rejects encaissement of transaction already on real account', function () {
        $transaction = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-11-15',
            'libelle' => 'Déjà encaissée',
            'montant_total' => 50.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $this->compteReel->id,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => SousCategorie::factory()->create()->id,
            'montant' => 50.00,
        ]);

        $facture = $this->service->creer($this->tiers->id);
        $this->service->ajouterTransactions($facture, [$transaction->id]);
        $this->service->valider($facture);
        $facture->refresh();

        // The facture is already acquittée (transaction on real account counts as règlement),
        // so the guard fires before per-transaction checks.
        $this->service->encaisser($facture, [$transaction->id], $this->compteReel->id);
    })->throws(RuntimeException::class, 'Cette facture est déjà intégralement réglée.');

    it('supports partial encaissement (only some transactions)', function () {
        $tx1 = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-11-15',
            'libelle' => 'Créance 1',
            'montant_total' => 100.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $this->compteCreances->id,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx1->id,
            'sous_categorie_id' => SousCategorie::factory()->create()->id,
            'montant' => 100.00,
        ]);

        $tx2 = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-11-15',
            'libelle' => 'Créance 2',
            'montant_total' => 200.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $this->compteCreances->id,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx2->id,
            'sous_categorie_id' => SousCategorie::factory()->create()->id,
            'montant' => 200.00,
        ]);

        $facture = $this->service->creer($this->tiers->id);
        $this->service->ajouterTransactions($facture, [$tx1->id, $tx2->id]);
        $this->service->valider($facture);
        $facture->refresh();

        $this->service->encaisser($facture, [$tx1->id], $this->compteReel->id);

        $facture->refresh();
        expect($facture->montantRegle())->toBe(100.00)
            ->and($facture->isAcquittee())->toBeFalse();

        $this->service->encaisser($facture, [$tx2->id], $this->compteReel->id);

        $facture->refresh();
        expect($facture->montantRegle())->toBe(300.00)
            ->and($facture->isAcquittee())->toBeTrue();
    });
});
