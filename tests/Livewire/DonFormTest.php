<?php

use App\Livewire\DonForm;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\Donateur;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create();

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

it('can save a don with existing donateur', function () {
    $donateur = Donateur::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Jean',
    ]);

    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '100.00')
        ->set('mode_paiement', 'virement')
        ->set('donateur_id', $donateur->id)
        ->set('compte_id', $this->compte->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'montant' => '100.00',
        'mode_paiement' => 'virement',
        'donateur_id' => $donateur->id,
        'saisi_par' => $this->user->id,
    ]);
});

it('can save an anonymous don', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '50.00')
        ->set('mode_paiement', 'especes')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'montant' => '50.00',
        'mode_paiement' => 'especes',
        'donateur_id' => null,
        'saisi_par' => $this->user->id,
    ]);
});

it('can save a don with new donateur (inline creation)', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '200.00')
        ->set('mode_paiement', 'cheque')
        ->set('creatingDonateur', true)
        ->set('new_donateur_nom', 'Martin')
        ->set('new_donateur_prenom', 'Sophie')
        ->set('new_donateur_email', 'sophie@example.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('donateurs', [
        'nom' => 'Martin',
        'prenom' => 'Sophie',
        'email' => 'sophie@example.com',
    ]);

    $donateur = Donateur::where('nom', 'Martin')->first();
    $this->assertDatabaseHas('dons', [
        'montant' => '200.00',
        'donateur_id' => $donateur->id,
        'saisi_par' => $this->user->id,
    ]);
});

it('validates required fields', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->call('save')
        ->assertHasErrors(['date', 'montant', 'mode_paiement']);
});

it('validates new donateur required fields when creating', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '100.00')
        ->set('mode_paiement', 'virement')
        ->set('creatingDonateur', true)
        ->call('save')
        ->assertHasErrors(['new_donateur_nom', 'new_donateur_prenom']);
});

it('can load existing don for editing', function () {
    $donateur = Donateur::factory()->create();
    $don = Don::factory()->create([
        'donateur_id' => $donateur->id,
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
        'montant' => 100.00,
        'mode_paiement' => 'especes',
        'objet' => 'Ancien objet',
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
        ->call('save')
        ->assertHasErrors(['date']);
});
