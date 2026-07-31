<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->compteProduit = Compte::factory()->numero('706')->create([
        'association_id' => (int) $this->association->id,
    ]);
    $this->compteClient = Compte::factory()->numero('411')->create([
        'association_id' => (int) $this->association->id,
        'est_systeme' => true,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('calcule les totaux depuis les seules ventilations de classes 6 et 7', function (): void {
    $transaction = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Cotisation compte-first',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compteProduit->id,
        'montant' => 100.00,
        'credit' => 100.00,
        'debit' => 0.00,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compteClient->id,
        'montant' => 0.00,
        'credit' => 0.00,
        'debit' => 100.00,
    ]);

    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->assertExitCode(0);
});

it('mesure les OD sur débit/crédit et non sur la colonne montant', function (): void {
    // Écriture de dotation aux provisions : 681 D / 486 C. Le générateur pose
    // montant = 0 sur ces lignes — la vérité est portée par débit/crédit.
    $compteDotation = Compte::factory()->numero('681')->create([
        'association_id' => (int) $this->association->id,
        'est_systeme' => true,
    ]);
    $compteCharges = Compte::factory()->numero('486')->create([
        'association_id' => (int) $this->association->id,
        'est_systeme' => true,
    ]);

    $transaction = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'depense',
        'date' => '2026-01-15',
        'libelle' => 'Dotation provision FNP',
        'montant_total' => 123.45,
        'type_ecriture' => 'normale',
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $compteDotation->id,
        'montant' => 0.00,
        'debit' => 123.45,
        'credit' => 0.00,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $compteCharges->id,
        'montant' => 0.00,
        'debit' => 0.00,
        'credit' => 123.45,
    ]);

    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->assertExitCode(0);
});

it('mesure un miroir d’extourne au négatif sans le signaler', function (): void {
    // Miroir d'extourne d'une recette : 707 D / 411 C, montant_total négatif.
    $transaction = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Miroir extourne',
        'montant_total' => -150.00,
        'type_ecriture' => 'extourne',
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compteProduit->id,
        'montant' => 0.00,
        'debit' => 150.00,
        'credit' => 0.00,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compteClient->id,
        'montant' => 0.00,
        'debit' => 0.00,
        'credit' => 150.00,
    ]);

    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->assertExitCode(0);
});

it('mesure l’adhésion liée sur débit/crédit et non sur la colonne montant', function (): void {
    $transaction = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Cotisation',
        'montant_total' => 25.00,
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compteProduit->id,
        'montant' => 0.00,
        'debit' => 0.00,
        'credit' => 25.00,
    ]);

    Adhesion::factory()->create([
        'association_id' => (int) $this->association->id,
        'transaction_id' => (int) $transaction->id,
        'montant_facial' => 25.00,
    ]);

    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->assertExitCode(0);
});

it('signale une divergence sur une ventilation compte-first', function (): void {
    $transaction = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Cotisation divergente',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compteProduit->id,
        'montant' => 80.00,
        'credit' => 80.00,
        'debit' => 0.00,
    ]);

    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->expectsOutputToContain('montant_total=100')
        ->assertExitCode(1);
});

it('ne compte pas deux fois une écriture opérationnelle et son règlement bancaire', function (): void {

    $user = User::factory()->create();
    $compteBancaire = CompteBancaire::factory()->create([
        'association_id' => (int) $this->association->id,
        'solde_initial' => 0,
    ]);
    $compteBanque = Compte::factory()->numero('5121')->create([
        'association_id' => (int) $this->association->id,
        'compte_bancaire_id' => (int) $compteBancaire->id,
    ]);

    $rapprochement = RapprochementBancaire::create([
        'association_id' => (int) $this->association->id,
        'compte_id' => (int) $compteBancaire->id,
        'date_fin' => '2026-01-31',
        'solde_ouverture' => 0,
        'solde_fin' => 100,
        'statut' => 'verrouille',
        'type' => 'bancaire',
        'saisi_par' => (int) $user->id,
        'verrouille_at' => now(),
    ]);

    $operationnelle = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Créance réglée',
        'montant_total' => 100,
        'type_ecriture' => 'normale',
        'journal' => 'vente',
        'compte_id' => (int) $compteBancaire->id,
        'rapprochement_id' => (int) $rapprochement->id,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $operationnelle->id,
        'compte_id' => (int) $this->compteProduit->id,
        'montant' => 100,
        'debit' => 0,
        'credit' => 100,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $operationnelle->id,
        'compte_id' => (int) $this->compteClient->id,
        'montant' => 0,
        'debit' => 100,
        'credit' => 0,
    ]);

    $reglement = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Encaissement bancaire',
        'montant_total' => 100,
        'type_ecriture' => 'normale',
        'journal' => 'banque',
        'compte_id' => (int) $compteBancaire->id,
        'rapprochement_id' => (int) $rapprochement->id,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $reglement->id,
        'compte_id' => (int) $this->compteClient->id,
        'montant' => 0,
        'debit' => 0,
        'credit' => 100,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $reglement->id,
        'compte_id' => (int) $compteBanque->id,
        'montant' => 0,
        'debit' => 100,
        'credit' => 0,
    ]);

    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->assertExitCode(0);
});

it('ignore les transactions techniques T2 et T4 même avec --fix', function (): void {
    $transaction = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Encaissement technique',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
    ]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compteClient->id,
        'montant' => 0.00,
        'credit' => 100.00,
        'debit' => 0.00,
    ]);

    $this->artisan('compta:check-integrity', ['--fix' => true, '--quiet-ok' => true])
        ->assertExitCode(0);

    expect((float) $transaction->fresh()->montant_total)->toBe(100.0);
});

it('signale un montant T4 différent de la somme des sources de remise', function (): void {
    $user = User::factory()->create();
    $compteBancaire = CompteBancaire::factory()->create([
        'association_id' => (int) $this->association->id,
    ]);
    $compte512 = Compte::factory()->numero('5121')->create([
        'association_id' => (int) $this->association->id,
        'compte_bancaire_id' => (int) $compteBancaire->id,
    ]);
    $remise = RemiseBancaire::create([
        'association_id' => (int) $this->association->id,
        'numero' => 1,
        'date' => '2026-01-15',
        'mode_paiement' => 'cheque',
        'compte_cible_id' => (int) $compteBancaire->id,
        'libelle' => 'Remise test',
        'saisi_par' => (int) $user->id,
    ]);

    $source = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'remise_id' => (int) $remise->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Chèque source',
        'montant_total' => 100.00,
        'mode_paiement' => 'cheque',
        'type_ecriture' => 'normale',
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $source->id,
        'compte_id' => (int) $this->compteProduit->id,
        'montant' => 100.00,
        'credit' => 100.00,
        'debit' => 0.00,
    ]);

    $t4 = Transaction::forceCreate([
        'association_id' => (int) $this->association->id,
        'remise_id' => (int) $remise->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Remise bancaire',
        'montant_total' => 90.00,
        'mode_paiement' => 'cheque',
        'type_ecriture' => 'normale',
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $t4->id,
        'compte_id' => (int) $compte512->id,
        'montant' => 0.00,
        'credit' => 0.00,
        'debit' => 90.00,
    ]);

    $this->artisan('compta:check-integrity', ['--fix' => true, '--quiet-ok' => true])
        ->expectsOutputToContain("T4#{$t4->id} montant_total=90")
        ->assertExitCode(1);

    expect((float) $t4->fresh()->montant_total)->toBe(90.0);
});
