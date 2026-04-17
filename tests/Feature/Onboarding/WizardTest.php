<?php

declare(strict_types=1);

use App\Livewire\Onboarding\Wizard;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->unonboarded()->create([
        'wizard_current_step' => 1,
        'wizard_state' => null,
    ]);
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

it('loads at step 1 on fresh mount', function () {
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('currentStep', 1);
});

it('resumes at persisted step on mount', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('currentStep', 3);
});

it('allows jumping backwards to a previous step', function () {
    $this->association->update(['wizard_current_step' => 4]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 2)
        ->assertSet('currentStep', 2);
});

it('rejects jumping forward beyond current step', function () {
    $this->association->update(['wizard_current_step' => 2]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 5)
        ->assertSet('currentStep', 2);
});
