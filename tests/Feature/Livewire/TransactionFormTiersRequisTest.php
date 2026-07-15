<?php

declare(strict_types=1);

/**
 * Le tiers est obligatoire pour toute recette/dépense saisie manuellement.
 *
 * Sans tiers, EcritureGenerator ne peut pas générer la contrepartie
 * (411 client / 401 fournisseur porte le tiers) : la transaction resterait
 * déséquilibrée (equilibree=false). Le formulaire doit donc rejeter la saisie
 * avant création — comptant comme créance.
 */

use App\Livewire\TransactionForm;
use App\Models\Tiers;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\CreatesPartieDoubleContext;

uses(RefreshDatabase::class);
uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    Config::set('compta.use_partie_double', true);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

function ligneVentilation(int $compteId, string $montant): array
{
    return [[
        'compte_id' => (string) $compteId,
        'operation_id' => '',
        'seance' => '',
        'montant' => $montant,
        'notes' => '',
        'piece_jointe_upload' => null,
        'piece_jointe_remove' => false,
    ]];
}

it('refuse une recette comptant sans tiers', function () {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', now()->format('Y-m-d'))
        ->set('libelle', 'Recette sans tiers')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', null)
        ->set('lignes', ligneVentilation($this->compte706->id, '150.00'))
        ->call('save')
        ->assertHasErrors(['tiers_id' => 'required']);

    expect(Transaction::where('libelle', 'Recette sans tiers')->exists())->toBeFalse();
});

it('refuse une recette créance sans tiers', function () {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', now()->format('Y-m-d'))
        ->set('libelle', 'Créance sans tiers')
        ->set('paiementRecu', false)
        ->set('mode_paiement', '')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', null)
        ->set('lignes', ligneVentilation($this->compte706->id, '150.00'))
        ->call('save')
        ->assertHasErrors(['tiers_id' => 'required']);

    expect(Transaction::where('libelle', 'Créance sans tiers')->exists())->toBeFalse();
});

it('refuse une dépense comptant sans tiers', function () {
    Livewire::test(TransactionForm::class)
        ->set('type', 'depense')
        ->set('date', now()->format('Y-m-d'))
        ->set('libelle', 'Dépense sans tiers')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', null)
        ->set('lignes', ligneVentilation($this->compte606->id, '80.00'))
        ->call('save')
        ->assertHasErrors(['tiers_id' => 'required']);

    expect(Transaction::where('libelle', 'Dépense sans tiers')->exists())->toBeFalse();
});

it('accepte une recette comptant avec tiers et génère une écriture équilibrée', function () {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', now()->format('Y-m-d'))
        ->set('libelle', 'Recette avec tiers')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', ligneVentilation($this->compte706->id, '150.00'))
        ->call('save')
        ->assertHasNoErrors();

    $tx = Transaction::where('libelle', 'Recette avec tiers')->firstOrFail();
    expect($tx->equilibree)->toBeTrue();
});
