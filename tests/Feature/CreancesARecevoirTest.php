<?php

// tests/Feature/CreancesARecevoirTest.php
declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\FactureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a system account named Créances à recevoir', function () {
    $compte = CompteBancaire::where('nom', 'Créances à recevoir')->first();

    expect($compte)->not->toBeNull()
        ->and($compte->est_systeme)->toBeTrue()
        ->and($compte->actif_recettes_depenses)->toBeFalse(); // v3: system accounts are deactivated
});

it('excludes Créances à recevoir from regular comptes list (actif_recettes_depenses)', function () {
    $creances = CompteBancaire::where('nom', 'Créances à recevoir')->first();
    $compteNormal = CompteBancaire::factory()->create(['actif_recettes_depenses' => true]);

    $comptes = CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get();
    expect($comptes->pluck('id')->toArray())->not->toContain($creances->id)
        ->and($comptes->pluck('id')->toArray())->toContain($compteNormal->id);
});

it('excludes Créances à recevoir from comptes list for non-recette contexts', function () {
    $creances = CompteBancaire::where('nom', 'Créances à recevoir')->first();

    $comptes = CompteBancaire::where('est_systeme', false)->orderBy('nom')->get();
    expect($comptes->pluck('id')->toArray())->not->toContain($creances->id);
});

describe('full workflow: créance → facture → encaissement', function () {
    it('completes the full lifecycle', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tiers = Tiers::factory()->create();
        $compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
        $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
        $service = app(FactureService::class);
        $sousCategorie = SousCategorie::factory()->create();

        // 1. Create recette on Créances à recevoir
        $transaction = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-06-15',
            'libelle' => 'Prestation yoga mutuelle',
            'montant_total' => 250.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $compteCreances->id,
            'tiers_id' => $tiers->id,
            'saisi_par' => $user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $sousCategorie->id,
            'montant' => 250.00,
        ]);

        // 2. Create facture and attach transaction
        $facture = $service->creer($tiers->id);
        $service->ajouterTransactions($facture, [$transaction->id]);

        // 3. Validate
        $service->valider($facture);
        $facture->refresh();

        expect($facture->statut)->toBe(StatutFacture::Validee)
            ->and($facture->montantRegle())->toBe(0.0)
            ->and($facture->isAcquittee())->toBeFalse();

        // 4. Encaisser
        $service->encaisser($facture, [$transaction->id], $compteReel->id);
        $facture->refresh();

        expect($facture->montantRegle())->toBe(250.0)
            ->and($facture->isAcquittee())->toBeTrue();

        // 5. Transaction is now on the real account
        $transaction->refresh();
        expect($transaction->compte_id)->toBe($compteReel->id);
    });

    it('handles mixed invoice (real + créance transactions)', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tiers = Tiers::factory()->create();
        $compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
        $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
        $service = app(FactureService::class);
        $sc = SousCategorie::factory()->create();

        // Transaction already paid (real account, statut_reglement=recu in v3)
        $txPaid = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-06-15',
            'libelle' => 'Déjà payée',
            'montant_total' => 100.00,
            'mode_paiement' => ModePaiement::Cb,
            'compte_id' => $compteReel->id,
            'tiers_id' => $tiers->id,
            'saisi_par' => $user->id,
            'statut_reglement' => 'recu',
        ]);
        TransactionLigne::create([
            'transaction_id' => $txPaid->id,
            'sous_categorie_id' => $sc->id,
            'montant' => 100.00,
        ]);

        // Transaction pending (créance)
        $txCreance = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-06-15',
            'libelle' => 'En attente mutuelle',
            'montant_total' => 200.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $compteCreances->id,
            'tiers_id' => $tiers->id,
            'saisi_par' => $user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $txCreance->id,
            'sous_categorie_id' => $sc->id,
            'montant' => 200.00,
        ]);

        // Create mixed facture
        $facture = $service->creer($tiers->id);
        $service->ajouterTransactions($facture, [$txPaid->id, $txCreance->id]);
        $service->valider($facture);
        $facture->refresh();

        // Partially paid (100 out of 300)
        expect((float) $facture->montant_total)->toBe(300.0)
            ->and($facture->montantRegle())->toBe(100.0)
            ->and($facture->isAcquittee())->toBeFalse();

        // Encaisser the créance
        $service->encaisser($facture, [$txCreance->id], $compteReel->id);
        $facture->refresh();

        expect($facture->montantRegle())->toBe(300.0)
            ->and($facture->isAcquittee())->toBeTrue();
    });
});
