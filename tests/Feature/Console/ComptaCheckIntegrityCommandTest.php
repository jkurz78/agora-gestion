<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
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
