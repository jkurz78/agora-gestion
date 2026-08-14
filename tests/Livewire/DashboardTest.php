<?php

use App\Livewire\Dashboard;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
    $this->exercice = 2025;
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('renders for authenticated user', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Solde général')
        ->assertSee('Résumé budget')
        ->assertSee('Dernières dépenses')
        ->assertSee('Dernières recettes')
        ->assertSee('Derniers dons')
        ->assertSee('Dernières adhésions')
        ->assertSee('Opérations')
        ->assertSee('Comptes bancaires')
        ->assertSee('Aucun compte bancaire configuré');
});

it('displays solde general', function () {
    // Le « Solde général » se lit sur le grand livre (classes 6 et 7) et non
    // plus sur montant_total : une transaction sans ligne comptable ne pèse
    // donc rien, ce qui est le comportement voulu — en partie double, toute
    // transaction réelle porte ses lignes. Voir
    // tests/Feature/Immobilisation/SoldeGeneralImmobilisationTest.php pour le
    // défaut qui a motivé le changement (une immobilisation comptée comme une
    // charge).
    $compte706 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706',
        'classe' => 7,
    ]);
    $compte606 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '606',
        'classe' => 6,
    ]);

    $ligne = function (Transaction $tx, Compte $compte, float $debit, float $credit): void {
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte->id,
            'debit' => $debit,
            'credit' => $credit,
            'montant' => $debit > 0 ? $debit : $credit,
        ]);
    };

    foreach ([[1000.00, '-11-01'], [500.00, '-12-01']] as [$montant, $jour]) {
        $tx = Transaction::factory()->asRecette()->create([
            'association_id' => $this->association->id,
            'date' => $this->exercice.$jour,
            'montant_total' => $montant,
            'saisi_par' => $this->user->id,
        ]);
        $ligne($tx, $compte706, 0.0, $montant);
    }

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-15',
        'montant_total' => 300.00,
        'saisi_par' => $this->user->id,
    ]);
    $ligne($depense, $compte606, 300.00, 0.0);

    // Solde = 1000 + 500 - 300 = 1200
    Livewire::test(Dashboard::class)
        ->assertSee('1 200,00');
});

it('shows dernieres adhesions', function () {
    $compteCotisation = Compte::factory()->pourCotisations()->create(['association_id' => $this->association->id]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-01',
        'montant_total' => 30.00,
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteCotisation->id,
        'montant' => 30.00,
        'credit' => 30.00,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('30,00');
});

it('displays comptes bancaires with soldes', function () {
    CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Compte Principal',
        'solde_initial' => 1500.00,
        'date_solde_initial' => '2024-01-01',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Compte Principal')
        ->assertSee('1 500,00');
});
