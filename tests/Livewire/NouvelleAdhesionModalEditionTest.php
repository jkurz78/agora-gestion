<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\NouvelleAdhesionModal;
use App\Models\Adhesion;
use App\Models\Exercice;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * L'assistant d'adhésion s'ouvre en mode « modification » sur une adhésion offerte,
 * depuis la fiche tiers. Il reprend la formule, la période et le motif ; le tiers ne
 * change pas et l'adhésion reste offerte (pas de montant, pas de paiement).
 */

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->tiers = Tiers::factory()->create(['nom' => 'Moquet', 'prenom' => 'Paul']);
    $this->formule = FormuleAdhesion::factory()->create(['mode' => 'exercice', 'nom' => 'Annuelle']);
    $this->offerte = Adhesion::factory()->create([
        'tiers_id' => $this->tiers->id,
        'formule_adhesion_id' => $this->formule->id,
        'exercice' => 2025,
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'transaction_id' => null,
        'notes' => 'Président',
    ]);
    session(['exercice_actif' => 2026]);
});

afterEach(function (): void {
    session()->forget('exercice_actif');
});

it('s\'ouvre pré-rempli sur l\'adhésion offerte', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $this->offerte->id)
        ->assertSet('visible', true)
        ->assertSet('adhesionId', (int) $this->offerte->id)
        ->assertSet('tiersId', (int) $this->tiers->id)
        ->assertSet('formuleId', (int) $this->formule->id)
        ->assertSet('exercice', 2025)
        ->assertSet('dateDebut', '2025-09-01')
        ->assertSet('notes', 'Président')
        ->assertSet('gratuite', true)
        ->assertSet('montant', 0.0)
        ->assertSeeHtml('Modifier l\'adhésion offerte')
        ->assertSee('MOQUET')
        ->assertDontSeeHtml('id="nouvelle-montant"')
        ->assertSee('Enregistrer');
});

it('enregistre la modification et prévient la fiche tiers', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $this->offerte->id)
        ->set('exercice', 2024)
        ->set('notes', 'Co-président')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('visible', false)
        ->assertDispatched('adhesion-modifiee')
        ->assertNotDispatched('adhesion-creee');

    $this->offerte->refresh();
    expect($this->offerte->exercice)->toBe(2024)
        ->and($this->offerte->notes)->toBe('Co-président')
        ->and(Adhesion::count())->toBe(1);
});

it('affiche le refus du service sans fermer', function (): void {
    Adhesion::factory()->create(['tiers_id' => $this->tiers->id, 'exercice' => 2024, 'date_debut' => '2024-09-01', 'date_fin' => '2025-08-31']);

    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $this->offerte->id)
        ->set('exercice', 2024)
        ->call('submit')
        ->assertSet('visible', true)
        ->assertSet('errorMessage', "Ce tiers a déjà une adhésion sur l'exercice 2024-2025.");

    expect($this->offerte->refresh()->exercice)->toBe(2025);
});

it('propose l\'exercice de l\'adhésion même clôturé', function (): void {
    Exercice::create(['association_id' => TenantContext::currentId(), 'annee' => 2025, 'statut' => StatutExercice::Cloture->value]);

    $annees = Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $this->offerte->id)
        ->viewData('availableYears');

    $creation = Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('nouvelle-adhesion', gratuite: true)
        ->viewData('availableYears');

    expect($annees)->toContain(2025)
        ->and($creation)->not->toContain(2025);
});

it('propose la formule de l\'adhésion même désactivée', function (): void {
    $this->formule->update(['actif' => false]);

    $formules = Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $this->offerte->id)
        ->viewData('formules');

    expect($formules->pluck('id')->map(fn ($id): int => (int) $id)->all())->toContain((int) $this->formule->id);
});

it('ne s\'ouvre pas sur une adhésion payée', function (): void {
    $payee = Adhesion::factory()->payee()->create(['tiers_id' => $this->tiers->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $payee->id)
        ->assertSet('visible', false)
        ->assertSet('adhesionId', null);
});

it('refuse la modification à un rôle en lecture seule', function (): void {
    $lecteur = User::factory()->create();
    $lecteur->associations()->attach(TenantContext::currentId(), ['role' => 'consultation', 'joined_at' => now()]);

    Livewire::actingAs($lecteur)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $this->offerte->id)
        ->assertForbidden();
});

it('l\'adhésion visée ne peut pas être changée depuis le navigateur', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->set('adhesionId', $this->offerte->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('une nouvelle saisie après une modification repart en création', function (): void {
    Livewire::actingAs($this->user)
        ->test(NouvelleAdhesionModal::class)
        ->dispatch('modifier-adhesion-offerte', adhesionId: $this->offerte->id)
        ->call('close')
        ->dispatch('nouvelle-adhesion', gratuite: true)
        ->assertSet('adhesionId', null)
        ->assertSet('tiersId', null)
        ->assertSee('Offrir une adhésion');
});
