<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $this->association->id]);

    $this->compte512 = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '512_BQ',
        'intitule' => 'Banque Principale',
        'classe' => 5,
    ]);
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);
});

afterEach(fn () => TenantContext::clear());

test('sensTresorerieSql retourne recette pour une recette comptant avec D 512x', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Vente,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 100.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 100.00,
        'credit' => 0,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('recette');
});

test('sensTresorerieSql retourne depense pour une dépense comptant avec C 512x', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Achat,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 200.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 0,
        'credit' => 200.00,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('depense');
});

test('sensTresorerieSql retourne depense pour miroir extourne de recette (C 512x)', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'journal' => JournalComptable::Vente,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 100.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 0,
        'credit' => 100.00,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('depense');
});

test('sensTresorerieSql retourne recette pour miroir extourne de dépense (D 512x)', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'journal' => JournalComptable::Achat,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 200.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 200.00,
        'credit' => 0,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('recette');
});

test('sensTresorerieSql fallback sur type pour tx sans lignes PD', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'equilibree' => false,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('recette');
});

test('sensTresorerieSql fallback depense pour tx legacy sans lignes PD', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'equilibree' => false,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('depense');
});
