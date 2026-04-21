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
    $this->get("/portail/{$this->asso->slug}/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSee('Je renonce au remboursement et propose un don par abandon de créance');
});

// ---------------------------------------------------------------------------
// Test 2 : La vue contient wire:model="abandonCreanceProposed"
// ---------------------------------------------------------------------------

it('form view: contient wire:model="abandonCreanceProposed" dans le HTML rendu', function () {
    $this->get("/portail/{$this->asso->slug}/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSeeText('Don par abandon de créance');
});

// ---------------------------------------------------------------------------
// Test 3 (bonus) : Livewire round-trip — set abandonCreanceProposed=true
// ---------------------------------------------------------------------------

it('form livewire: set abandonCreanceProposed=true est bien à true', function () {
    $component = new Form;
    $component->mount($this->asso);
    $component->abandonCreanceProposed = true;

    expect($component->abandonCreanceProposed)->toBeTrue();
});
