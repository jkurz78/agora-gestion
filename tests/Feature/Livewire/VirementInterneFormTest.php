<?php

declare(strict_types=1);

use App\Livewire\VirementInterneForm;
use App\Models\Association;
use App\Models\User;
use App\Models\VirementInterne;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('s\'ouvre pour un nouveau virement via open-virement-form', function () {
    Livewire::test(VirementInterneForm::class)
        ->dispatch('open-virement-form', id: null)
        ->assertSet('showForm', true)
        ->assertSet('virementId', null);
});

it('s\'ouvre en édition via open-virement-form avec un id', function () {
    $virement = VirementInterne::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-01',
    ]);

    Livewire::test(VirementInterneForm::class)
        ->dispatch('open-virement-form', id: $virement->id)
        ->assertSet('showForm', true)
        ->assertSet('virementId', $virement->id)
        ->assertSet('montant', (string) $virement->montant);
});
