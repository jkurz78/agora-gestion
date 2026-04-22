<?php

declare(strict_types=1);

use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Test 1 : La vue contient le label "Je renonce au remboursement..."
// ---------------------------------------------------------------------------

it('form view: contient le label abandon de créance', function () {
    $this->get("/{$this->asso->slug}/portail/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSee('Je renonce au remboursement et propose un don par abandon de créance');
});

// ---------------------------------------------------------------------------
// Test 2 : La vue contient wire:model="abandonCreanceProposed"
// ---------------------------------------------------------------------------

it('form view: contient wire:model="abandonCreanceProposed" dans le HTML rendu', function () {
    $this->get("/{$this->asso->slug}/portail/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSee('wire:model="abandonCreanceProposed"', false);
});

// ---------------------------------------------------------------------------
// Test 3 : la propriété abandonCreanceProposed est muable (assignation directe)
// ---------------------------------------------------------------------------

it('form component: la propriété abandonCreanceProposed accepte la valeur true', function () {
    $component = new Form;
    $component->mount($this->asso);
    $component->abandonCreanceProposed = true;

    expect($component->abandonCreanceProposed)->toBeTrue();
});
