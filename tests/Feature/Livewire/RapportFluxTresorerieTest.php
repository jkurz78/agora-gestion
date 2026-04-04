<?php

declare(strict_types=1);

use App\Livewire\RapportFluxTresorerie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Exercice::create(['annee' => 2025, 'statut' => 'ouvert']);
    session(['exercice_actif' => 2025]);
    CompteBancaire::factory()->create([
        'solde_initial' => 10000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
});

it('affiche le composant avec la synthèse', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 5000.00,
        'compte_id' => CompteBancaire::first()->id,
    ]);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Rapport provisoire')
        ->assertSee('5 000,00')
        ->assertSee('10 000,00');
});

it('affiche la ligne flux dépliable avec les totaux annuels', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 8000.00,
        'compte_id' => CompteBancaire::first()->id,
    ]);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSeeHtml('Flux de l\'exercice')
        ->assertSee('8 000,00');
});

it('contient le détail mensuel dans le HTML (masqué par Alpine)', function () {
    // Alpine.js gère l'affichage côté client, le HTML est rendu côté serveur
    Livewire::test(RapportFluxTresorerie::class)
        ->assertSeeHtml('Septembre 2025');
});

it('affiche rapport définitif quand exercice clôturé', function () {
    Exercice::where('annee', 2025)->update([
        'statut' => 'cloture',
        'date_cloture' => '2026-09-15 10:00:00',
    ]);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Rapport définitif')
        ->assertSee('15/09/2026');
});

it('affiche le bloc rapprochement avec le nombre d\'écritures non pointées', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 1500.00,
        'compte_id' => CompteBancaire::first()->id,
        'rapprochement_id' => null,
    ]);

    Livewire::test(RapportFluxTresorerie::class)
        ->assertSee('Rapprochement bancaire')
        ->assertSee('1 500,00');
});
