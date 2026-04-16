<?php

declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('met à jour selectedLabel et selectedType quand tiersId change programmatiquement', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'type' => 'particulier', 'prenom' => 'Jean']);

    Livewire::actingAs($this->user)
        ->test(TiersAutocomplete::class)
        ->set('tiersId', $tiers->id)
        ->assertSet('selectedLabel', 'Jean DUPONT')
        ->assertSet('selectedType', 'particulier');
});

it('vide selectedLabel et selectedType quand tiersId devient null', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont']);

    Livewire::actingAs($this->user)
        ->test(TiersAutocomplete::class, ['tiersId' => $tiers->id])
        ->set('tiersId', null)
        ->assertSet('selectedLabel', null)
        ->assertSet('selectedType', null);
});
