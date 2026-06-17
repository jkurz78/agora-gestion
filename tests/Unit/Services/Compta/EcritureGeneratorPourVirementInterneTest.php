<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Tenant\TenantContext;

it('TypeTransaction has a Virement case with correct value and label', function () {
    $case = TypeTransaction::Virement;
    expect($case->value)->toBe('virement');
    expect($case->label())->toBe('Virement');
});

it('Transaction belongsTo VirementInterne via virement_interne_id', function () {
    // saisi_par est FK NOT NULL → crée un user de test (User n'est pas tenant-scopé)
    $user = User::factory()->create();

    $compteBancaire1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $compteBancaire2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    $virement = VirementInterne::create([
        'association_id' => TenantContext::currentId(),
        'date' => '2026-01-15',
        'montant' => 500.00,
        'compte_source_id' => $compteBancaire1->id,
        'compte_destination_id' => $compteBancaire2->id,
        'numero_piece' => '2025-2026:99999',
        'saisi_par' => $user->id,
    ]);

    $transaction = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Virement,
        'date' => '2026-01-15',
        'libelle' => 'Virement interne',
        'montant_total' => 500.00,
        'saisi_par' => $user->id,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => \App\Enums\JournalComptable::Banque,
        'numero_piece' => '2025-2026:99999',
        'virement_interne_id' => $virement->id,
    ]);

    expect($transaction->virementInterne)->toBeInstanceOf(VirementInterne::class);
    expect((int) $transaction->virementInterne->id)->toBe((int) $virement->id);

    expect($virement->fresh()->transaction)->toBeInstanceOf(Transaction::class);
    expect((int) $virement->fresh()->transaction->id)->toBe((int) $transaction->id);
});
