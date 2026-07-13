<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
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
