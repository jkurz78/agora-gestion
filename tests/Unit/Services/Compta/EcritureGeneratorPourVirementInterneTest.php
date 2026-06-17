<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
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

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function creerDeuxComptesBancairesAvec512(): array
{
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();

    $compte512Source = Compte::where('compte_bancaire_id', $cb1->id)
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
    $compte512Dest = Compte::where('compte_bancaire_id', $cb2->id)
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();

    return [$cb1, $cb2, $compte512Source, $compte512Dest];
}

function creerVirement(CompteBancaire $source, CompteBancaire $dest, float $montant = 1000.00): VirementInterne
{
    return VirementInterne::create([
        'association_id' => TenantContext::currentId(),
        'date' => '2026-01-15',
        'montant' => $montant,
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'reference' => 'VIR-TEST-001',
        'numero_piece' => '2025-2026:00042',
        'saisi_par' => User::factory()->create()->id,
    ]);
}

// ---------------------------------------------------------------------------
// beforeEach
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();
});

// ---------------------------------------------------------------------------
// Tests EcritureGenerator::pourVirementInterne
// ---------------------------------------------------------------------------

it('generates a balanced 2-line entry for a virement interne', function () {
    [$cb1, $cb2, $compte512Source, $compte512Dest] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);

    $generator = app(EcritureGenerator::class);
    $transaction = $generator->pourVirementInterne($virement);

    // Transaction header
    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->type)->toBe(TypeTransaction::Virement);
    expect((float) $transaction->montant_total)->toBe(1000.00);
    expect($transaction->mode_paiement)->toBe(ModePaiement::Virement);
    expect($transaction->type_ecriture)->toBe('normale');
    expect($transaction->journal)->toBe(JournalComptable::Banque);
    expect($transaction->numero_piece)->toBe('2025-2026:00042');
    expect((int) $transaction->virement_interne_id)->toBe((int) $virement->id);
    expect($transaction->equilibree)->toBeTrue();

    // 2 lignes exactement
    $lignes = $transaction->lignes;
    expect($lignes)->toHaveCount(2);

    // Ligne débit = destination (argent arrive)
    $ligneDebit = $lignes->firstWhere('debit', '>', 0);
    expect($ligneDebit)->not->toBeNull();
    expect((int) $ligneDebit->compte_id)->toBe((int) $compte512Dest->id);
    expect((float) $ligneDebit->debit)->toBe(1000.00);
    expect((float) $ligneDebit->credit)->toBe(0.00);
    expect($ligneDebit->tiers_id)->toBeNull();
    expect($ligneDebit->sous_categorie_id)->toBeNull();
    expect($ligneDebit->lettrage_code)->toBeNull();

    // Ligne crédit = source (argent part)
    $ligneCredit = $lignes->firstWhere('credit', '>', 0);
    expect($ligneCredit)->not->toBeNull();
    expect((int) $ligneCredit->compte_id)->toBe((int) $compte512Source->id);
    expect((float) $ligneCredit->debit)->toBe(0.00);
    expect((float) $ligneCredit->credit)->toBe(1000.00);
    expect($ligneCredit->tiers_id)->toBeNull();
    expect($ligneCredit->sous_categorie_id)->toBeNull();
    expect($ligneCredit->lettrage_code)->toBeNull();
});

it('uses the virement reference as libelle when present', function () {
    [$cb1, $cb2] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);

    $transaction = app(EcritureGenerator::class)->pourVirementInterne($virement);

    expect($transaction->libelle)->toBe('VIR-TEST-001');
});

it('defaults libelle to Virement interne when reference is null', function () {
    [$cb1, $cb2] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);
    $virement->update(['reference' => null]);

    $transaction = app(EcritureGenerator::class)->pourVirementInterne($virement->fresh());

    expect($transaction->libelle)->toBe('Virement interne');
});

it('throws RuntimeException when source 512X is missing', function () {
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();
    // Delete the 512X for cb1
    Compte::where('compte_bancaire_id', $cb1->id)->delete();

    $virement = creerVirement($cb1, $cb2);

    app(EcritureGenerator::class)->pourVirementInterne($virement);
})->throws(\RuntimeException::class, 'source');

it('throws RuntimeException when destination 512X is missing', function () {
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();
    // Delete the 512X for cb2
    Compte::where('compte_bancaire_id', $cb2->id)->delete();

    $virement = creerVirement($cb1, $cb2);

    app(EcritureGenerator::class)->pourVirementInterne($virement);
})->throws(\RuntimeException::class, 'destination');

it('throws InvalidArgumentException when source and destination resolve to same 512X', function () {
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();

    // Same CompteBancaire on both sides
    $virement = creerVirement($cb1, $cb1);

    app(EcritureGenerator::class)->pourVirementInterne($virement);
})->throws(\InvalidArgumentException::class, 'identiques');

it('Transaction::montantSigne returns positive for Virement type', function () {
    $tx = new Transaction();
    $tx->type = TypeTransaction::Virement;
    $tx->montant_total = 500.00;

    expect($tx->montantSigne())->toBe(500.00);
});

it('Transaction::sensTresorerie returns Recette for normal Virement', function () {
    $tx = new Transaction();
    $tx->type = TypeTransaction::Virement;
    $tx->type_ecriture = 'normale';

    expect($tx->sensTresorerie())->toBe(\App\Enums\Sens::Recette);
});

it('scopeOperationnel excludes Virement transactions', function () {
    [$cb1, $cb2, $compte512Source, $compte512Dest] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);

    $transaction = app(EcritureGenerator::class)->pourVirementInterne($virement);

    $found = Transaction::operationnel()->where('id', $transaction->id)->exists();
    expect($found)->toBeFalse();
});
