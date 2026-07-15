<?php

declare(strict_types=1);

/**
 * Affichage du compte sur une transaction verrouillée par facture.
 *
 * Constat recette 2026-07-15 : la branche lecture seule ($isLockedByFacture)
 * du formulaire affichait l'intitulé du compte sans sa famille, contrairement
 * au chip CompteAutocomplete du mode édition. L'affichage doit être identique
 * dans les deux modes : famille (libellé) + intitulé.
 */

use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Famille;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create(['exercice_mois_debut' => 9]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('affiche famille + intitulé du compte sur une transaction verrouillée par facture', function () {
    Famille::factory()->create([
        'association_id' => $this->association->id,
        'code' => '70',
        'nom' => 'Ventes et prestations',
    ]);
    $compte706 = Compte::factory()->numero('706B')->create([
        'intitule' => 'Parcours thérapeutiques',
    ]);
    $compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $transaction = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteBancaire->id,
        'tiers_id' => $tiers->id,
        'montant_total' => 80.00,
    ]);
    TransactionLigne::where('transaction_id', $transaction->id)->delete();
    TransactionLigne::withoutEvents(fn () => TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte706->id,
        'montant' => 80.00,
        'debit' => 0,
        'credit' => 0,
    ]));

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => 'validee',
        'tiers_id' => $tiers->id,
        'montant_total' => 80.00,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
    ]);
    $facture->transactions()->attach($transaction->id);

    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->assertSet('isLockedByFacture', true)
        ->assertSee('Ventes et prestations')
        ->assertSee('Parcours thérapeutiques');
});
