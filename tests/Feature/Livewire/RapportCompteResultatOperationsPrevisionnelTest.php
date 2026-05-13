<?php

declare(strict_types=1);

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Operation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

it('a parSeances et parTiers à true par défaut, previsionnel à false', function (): void {
    $comp = Livewire::test(RapportCompteResultatOperations::class);

    expect($comp->get('parSeances'))->toBeTrue()
        ->and($comp->get('parTiers'))->toBeTrue()
        ->and($comp->get('previsionnel'))->toBeFalse();
});

it('lit le param URL prev=1 dans previsionnel', function (): void {
    $comp = Livewire::withQueryParams(['prev' => '1'])
        ->test(RapportCompteResultatOperations::class);

    expect($comp->get('previsionnel'))->toBeTrue();
});

it('passe previsionnel au service et expose previsionsCharges/Produits à la vue', function (): void {
    $op = Operation::factory()->create();

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('previsionnel', true)
        ->assertViewHas('previsionsCharges')
        ->assertViewHas('previsionsProduits');
});

it('exportUrl contient prev=1 quand previsionnel activé', function (): void {
    $op = Operation::factory()->create();

    $comp = Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('previsionnel', true);

    $url = $comp->instance()->exportUrl('pdf');

    expect($url)->toContain('prev=1');
});
