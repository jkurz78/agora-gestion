<?php

use App\Livewire\CotisationForm;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tiers = Tiers::factory()->membre()->create();
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders for a tiers membre', function () {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->assertOk()
        ->assertSee('Cotisations');
});

it('can add a cotisation', function () {
    $compte = CompteBancaire::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->set('montant', '50.00')
        ->set('date_paiement', '2025-10-01')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('cotisations', [
        'tiers_id' => $this->tiers->id,
        'exercice' => 2025,
        'montant' => '50.00',
        'mode_paiement' => 'virement',
    ]);
});

it('validates required fields when adding a cotisation', function () {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->set('montant', '')
        ->set('mode_paiement', '')
        ->set('date_paiement', '')
        ->call('save')
        ->assertHasErrors(['montant', 'mode_paiement', 'date_paiement']);
});

it('can delete a cotisation via soft delete', function () {
    $cotisation = Cotisation::factory()->create([
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->call('delete', $cotisation->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('cotisations', ['id' => $cotisation->id]);
});

it('rejette une date_paiement avant le début de l\'exercice', function () {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->set('date_paiement', '2025-08-31')
        ->set('montant', '50')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date_paiement']);
});

it('rejette une date_paiement après la fin de l\'exercice', function () {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->set('date_paiement', '2026-09-01')
        ->set('montant', '50')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date_paiement']);
});
