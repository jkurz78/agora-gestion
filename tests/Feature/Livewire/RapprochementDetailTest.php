<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Livewire\RapprochementDetail;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create();
    $this->rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'solde_ouverture' => 1000.00,
        'solde_fin' => 1200.00,
        'date_fin' => '2026-03-31',
        'saisi_par' => $this->user->id,
    ]);
});

it('affiche la colonne # avec l\'id de la transaction', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date' => '2026-03-15',
        'montant_total' => 100.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee((string) $tx->id);
});

it('affiche la colonne Tiers pour une recette', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'type' => 'particulier']);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'tiers_id' => $tiers->id,
        'date' => '2026-03-15',
        'montant_total' => 100.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('Jean Dupont');
});

it('affiche la colonne Tiers pour un don', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Marie', 'type' => 'particulier']);
    Don::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'tiers_id' => $tiers->id,
        'date' => '2026-03-10',
        'montant' => 50.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('Marie Martin');
});

it('affiche la colonne Tiers pour une cotisation via tiers()', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Durand', 'prenom' => 'Pierre', 'type' => 'particulier']);
    Cotisation::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'tiers_id' => $tiers->id,
        'date_paiement' => '2026-03-05',
        'montant' => 30.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('Pierre Durand');
});

it('affiche les totaux débits et crédits pointés', function () {
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date' => '2026-03-10',
        'montant_total' => 150.00,
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date' => '2026-03-15',
        'montant_total' => 300.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('150,00')   // total débit pointé
        ->assertSee('300,00');  // total crédit pointé
});

it('masque les écritures pointées quand la case est cochée', function () {
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date' => '2026-03-10',
        'montant_total' => 100.00,
        'libelle' => 'Recette pointée',
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
        'date' => '2026-03-15',
        'montant_total' => 50.00,
        'libelle' => 'Recette non pointée',
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->set('masquerPointees', true)
        ->assertSee('Recette non pointée')
        ->assertDontSee('Recette pointée');
});

it('affiche toutes les écritures quand la case est décochée', function () {
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date' => '2026-03-10',
        'montant_total' => 100.00,
        'libelle' => 'Recette pointée',
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->set('masquerPointees', false)
        ->assertSee('Recette pointée');
});

it('peut modifier le solde de fin', function () {
    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateSoldeFin', '1500.50')
        ->assertHasNoErrors();

    expect($this->rapprochement->fresh()->solde_fin)->toEqual('1500.50');
});

it('refuse un solde de fin non numérique', function () {
    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateSoldeFin', 'abc')
        ->assertHasErrors(['solde_fin']);
});

it('peut modifier la date de fin', function () {
    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateDateFin', '2026-04-30')
        ->assertHasNoErrors();

    expect($this->rapprochement->fresh()->date_fin->format('Y-m-d'))->toBe('2026-04-30');
});

it('refuse une date de fin antérieure au dernier rapprochement verrouillé', function () {
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'date_fin' => '2026-02-28',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateDateFin', '2026-02-01')
        ->assertHasErrors(['date_fin']);
});

it('ne modifie pas les champs si le rapprochement est verrouillé', function () {
    $this->rapprochement->update([
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement->fresh()])
        ->call('updateSoldeFin', '9999.00')
        ->assertHasErrors(['solde_fin']);
});
