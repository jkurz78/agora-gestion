<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 6 : VirementInterneForm refuse un montant négatif.
 *
 * VirementInterneForm.save() validait le montant avec `min:0.01`, ce qui accepte
 * `-50` (la règle Laravel `min` compare la valeur numérique mais est permissive
 * sur le signe en certaines configurations). Le patch Step 6 remplace `min:0.01`
 * par `MontantValidation::RULE` (`gt:0`) qui rejette strictement tout montant <= 0.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Enums\StatutExercice;
use App\Livewire\Concerns\MontantValidation;
use App\Livewire\VirementInterneForm;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    session(['exercice_actif' => 2025]);

    $this->compteSource = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'solde_initial' => 1000.0,
    ]);
    $this->compteDestination = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'solde_initial' => 0.0,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('virement_interne_form_refuse_montant_negatif', function (): void {
    $component = Livewire::test(VirementInterneForm::class);

    $component->dispatch('open-virement-form', id: null);

    $component->set('date', '2025-10-15')
        ->set('montant', '-50')
        ->set('compte_source_id', $this->compteSource->id)
        ->set('compte_destination_id', $this->compteDestination->id)
        ->call('save');

    $component->assertHasErrors(['montant']);

    expect($component->errors()->first('montant'))
        ->toBe(MontantValidation::MESSAGE);
});

it('virement_interne_form_refuse_montant_zero', function (): void {
    $component = Livewire::test(VirementInterneForm::class);

    $component->dispatch('open-virement-form', id: null);

    $component->set('date', '2025-10-15')
        ->set('montant', '0')
        ->set('compte_source_id', $this->compteSource->id)
        ->set('compte_destination_id', $this->compteDestination->id)
        ->call('save');

    $component->assertHasErrors(['montant']);

    expect($component->errors()->first('montant'))
        ->toBe(MontantValidation::MESSAGE);
});

it('virement_interne_form_accepte_montant_positif', function (): void {
    $component = Livewire::test(VirementInterneForm::class);

    $component->dispatch('open-virement-form', id: null);

    $component->set('date', '2025-10-15')
        ->set('montant', '150')
        ->set('compte_source_id', $this->compteSource->id)
        ->set('compte_destination_id', $this->compteDestination->id)
        ->call('save');

    $component->assertHasNoErrors(['montant']);
});
