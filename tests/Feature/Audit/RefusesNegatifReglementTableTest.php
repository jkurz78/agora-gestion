<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 6 : ReglementTable refuse un montant négatif.
 *
 * ReglementTable expose `updateMontant()` pour l'édition inline du montant prévu
 * d'un règlement. Cette méthode acceptait jusqu'ici n'importe quelle valeur float,
 * y compris des négatifs. Le patch Step 6 ajoute une validation `MontantValidation::RULE`
 * avant la persistance.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Livewire\Concerns\MontantValidation;
use App\Livewire\ReglementTable;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
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

    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $this->seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
    ]);

    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $this->participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('reglement_table_refuse_montant_negatif_sur_updateMontant', function (): void {
    $component = Livewire::test(ReglementTable::class, ['operation' => $this->operation]);

    $component->call('updateMontant', $this->participant->id, $this->seance->id, '-50');

    $component->assertHasErrors(['montant']);

    expect($component->errors()->first('montant'))
        ->toBe(MontantValidation::MESSAGE);
});

it('reglement_table_accepte_montant_positif_sur_updateMontant', function (): void {
    $component = Livewire::test(ReglementTable::class, ['operation' => $this->operation]);

    $component->call('updateMontant', $this->participant->id, $this->seance->id, '50');

    $component->assertHasNoErrors(['montant']);
});
