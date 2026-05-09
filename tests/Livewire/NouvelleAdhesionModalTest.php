<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\NouvelleAdhesionModal;
use App\Models\Adhesion;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
    $this->tiers = Tiers::factory()->create();
    $this->compte = CompteBancaire::factory()->create();
    $this->formuleExercice = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'mode' => 'exercice',
        'montant_par_defaut' => 30.00,
    ]);
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    session()->forget('exercice_actif');
});

it('s\'ouvre fermé par défaut et s\'ouvre via l\'event nouvelle-adhesion', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->assertSet('visible', false)
        ->dispatch('nouvelle-adhesion')
        ->assertSet('visible', true)
        ->assertSet('gratuite', false);
});

it('s\'ouvre en mode gratuite via le payload de l\'event', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('nouvelle-adhesion', gratuite: true)
        ->assertSet('visible', true)
        ->assertSet('gratuite', true)
        ->assertSet('montant', 0.0);
});

it('crée une adhésion gratuite (montant=0) sans transaction', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('nouvelle-adhesion', gratuite: true)
        ->set('tiersId', $this->tiers->id)
        ->set('formuleId', $this->formuleExercice->id)
        ->set('exercice', 2025)
        ->set('montant', 0.0)
        ->set('notes', 'Membre d\'honneur')
        ->call('submit')
        ->assertSet('visible', false)
        ->assertDispatched('adhesion-creee');

    expect(Adhesion::count())->toBe(1);
    expect(Transaction::count())->toBe(0);
    $adhesion = Adhesion::first();
    expect($adhesion->notes)->toBe('Membre d\'honneur');
    expect($adhesion->formule_adhesion_id)->toBe($this->formuleExercice->id);
});

it('crée une adhésion payée avec transaction', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('nouvelle-adhesion')
        ->set('tiersId', $this->tiers->id)
        ->set('formuleId', $this->formuleExercice->id)
        ->set('exercice', 2025)
        ->set('montant', 30.00)
        ->set('datePaiement', '2025-10-15')
        ->set('modePaiement', ModePaiement::Cb->value)
        ->set('compteId', $this->compte->id)
        ->set('reference', 'CB-001')
        ->call('submit')
        ->assertSet('visible', false)
        ->assertDispatched('adhesion-creee');

    expect(Adhesion::count())->toBe(1);
    expect(Transaction::count())->toBe(1);
    expect((float) Transaction::first()->montant_total)->toBe(30.00);
});

it('mode durée affiche date_debut + date_fin readonly', function (): void {
    // La 1re formule ('exercice') doit être inactive pour la contrainte 1-active-par-sous-cat
    $this->formuleExercice->update(['actif' => false]);
    $formuleDuree = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
        'montant_par_defaut' => 50.00,
    ]);

    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('nouvelle-adhesion')
        ->set('tiersId', $this->tiers->id)
        ->set('formuleId', $formuleDuree->id)
        ->set('dateDebut', '2025-10-15')
        ->assertSet('dateFinCalculee', '2026-10-15');
});

it('refuse un doublon avec un message d\'erreur fr', function (): void {
    Adhesion::factory()->create([
        'tiers_id' => $this->tiers->id,
        'exercice' => 2025,
    ]);

    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('nouvelle-adhesion', gratuite: true)
        ->set('tiersId', $this->tiers->id)
        ->set('formuleId', $this->formuleExercice->id)
        ->set('exercice', 2025)
        ->set('montant', 0.0)
        ->set('notes', 'doublon')
        ->call('submit')
        ->assertSet('visible', true) // reste ouvert
        ->assertSee('déjà une adhésion');

    expect(Adhesion::count())->toBe(1);
});

it('valide les champs obligatoires', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('nouvelle-adhesion')
        ->set('tiersId', null)
        ->set('formuleId', null)
        ->set('montant', 30.00)
        ->set('datePaiement', null)
        ->call('submit')
        ->assertHasErrors(['tiersId', 'formuleId']);
});
