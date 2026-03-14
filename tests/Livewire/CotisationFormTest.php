<?php

declare(strict_types=1);

use App\Livewire\CotisationForm;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->tiers = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'Dupont', 'prenom' => 'Jean']);
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    session()->forget('exercice_actif');
});

it('renders the form component', function (): void {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->assertOk()
        ->assertSee('Nouvelle cotisation');
});

it('can save a cotisation with a tiers', function (): void {
    $compte = CompteBancaire::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->call('showNewForm')
        ->set('tiers_id', $this->tiers->id)
        ->set('montant', '50.00')
        ->set('date_paiement', '2025-10-01')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('cotisation-saved');

    $this->assertDatabaseHas('cotisations', [
        'tiers_id'      => $this->tiers->id,
        'exercice'      => 2025,
        'montant'       => '50.00',
        'mode_paiement' => 'virement',
    ]);
});

it('requires a tiers', function (): void {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->call('showNewForm')
        ->set('montant', '50.00')
        ->set('date_paiement', '2025-10-01')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['tiers_id']);
});

it('validates required fields', function (): void {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->call('showNewForm')
        ->set('date_paiement', '')
        ->call('save')
        ->assertHasErrors(['tiers_id', 'montant', 'mode_paiement', 'date_paiement']);
});

it('rejette une date_paiement avant le début de l\'exercice', function (): void {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->call('showNewForm')
        ->set('tiers_id', $this->tiers->id)
        ->set('date_paiement', '2025-08-31')
        ->set('montant', '50')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date_paiement']);
});

it('rejette une date_paiement après la fin de l\'exercice', function (): void {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->call('showNewForm')
        ->set('tiers_id', $this->tiers->id)
        ->set('date_paiement', '2026-09-01')
        ->set('montant', '50')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date_paiement']);
});
