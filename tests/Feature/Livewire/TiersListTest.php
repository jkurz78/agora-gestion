<?php

declare(strict_types=1);

use App\Livewire\TiersList;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
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

it('affiche un en-tête table-dark avec le style bleu', function () {
    Livewire::test(TiersList::class)
        ->assertSeeHtml('class="table-dark"')
        ->assertSeeHtml('--bs-table-bg:#3d5473');
});

it('affiche bi-check-lg pour un tiers avec pour_depenses=true', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'pour_depenses' => true, 'pour_recettes' => false]);

    Livewire::test(TiersList::class)
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('affiche un tiret pour un tiers avec pour_depenses=false', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'pour_depenses' => false, 'pour_recettes' => false]);

    Livewire::test(TiersList::class)
        ->assertSeeHtml('text-muted">—</span>');
});

it('affiche les en-têtes raccourcis Dép. et Rec.', function () {
    Livewire::test(TiersList::class)
        ->assertSee('Dép.')
        ->assertSee('Rec.');
});

it('n\'affiche plus l\'en-tête table-light', function () {
    Livewire::test(TiersList::class)
        ->assertDontSeeHtml('table-light');
});
