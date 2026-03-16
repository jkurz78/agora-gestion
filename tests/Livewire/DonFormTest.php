<?php

use App\Livewire\DonForm;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create();
    $this->sousCategorie = SousCategorie::factory()->pourDons()->create(['nom' => 'Dons manuels']);

    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders the form component', function () {
    Livewire::test(DonForm::class)
        ->assertOk()
        ->assertSee('Nouveau don');
});

it('can save a don with existing tiers', function () {
    $tiers = Tiers::factory()->pourRecettes()->create([
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'type' => 'particulier',
    ]);

    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '100.00')
        ->set('mode_paiement', 'virement')
        ->set('tiers_id', $tiers->id)
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->set('compte_id', $this->compte->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'montant' => '100.00',
        'mode_paiement' => 'virement',
        'tiers_id' => $tiers->id,
        'saisi_par' => $this->user->id,
    ]);
});

it('can save an anonymous don', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '50.00')
        ->set('mode_paiement', 'especes')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'montant' => '50.00',
        'mode_paiement' => 'especes',
        'tiers_id' => null,
        'saisi_par' => $this->user->id,
    ]);
});

it('validates required fields', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->call('save')
        ->assertHasErrors(['date', 'montant', 'mode_paiement', 'sous_categorie_id']);
});

it('can load existing don for editing', function () {
    $tiers = Tiers::factory()->pourRecettes()->create();
    $don = Don::factory()->create([
        'tiers_id' => $tiers->id,
        'montant' => 150.00,
        'mode_paiement' => 'cb',
        'objet' => 'Don annuel',
        'saisi_par' => $this->user->id,
        'compte_id' => $this->compte->id,
    ]);

    Livewire::test(DonForm::class)
        ->call('edit', $don->id)
        ->assertSet('donId', $don->id)
        ->assertSet('mode_paiement', 'cb')
        ->assertSet('objet', 'Don annuel')
        ->assertSet('showForm', true);
});

it('can update an existing don', function () {
    $don = Don::factory()->create([
        'date' => '2025-10-15',
        'montant' => 100.00,
        'mode_paiement' => 'especes',
        'objet' => 'Ancien objet',
        'sous_categorie_id' => $this->sousCategorie->id,
        'saisi_par' => $this->user->id,
        'compte_id' => $this->compte->id,
    ]);

    Livewire::test(DonForm::class)
        ->call('edit', $don->id)
        ->set('montant', '250.00')
        ->set('objet', 'Nouvel objet')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'id' => $don->id,
        'montant' => '250.00',
        'objet' => 'Nouvel objet',
    ]);
});

it('rejette une date hors exercice', function () {
    Livewire::test(DonForm::class)
        ->call('showNewForm')
        ->set('date', '2025-08-01')
        ->set('montant', '100.00')
        ->set('mode_paiement', 'virement')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->call('save')
        ->assertHasErrors(['date']);
});
