<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\User;
use App\Services\VirementInterneService;
use App\Tenant\TenantContext;

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

it('create assigne un numero_piece non null', function () {
    $compte1 = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $compte2 = CompteBancaire::factory()->create(['association_id' => $this->association->id]);

    $virement = app(VirementInterneService::class)->create([
        'date' => '2025-10-01',
        'montant' => 200,
        'compte_source_id' => $compte1->id,
        'compte_destination_id' => $compte2->id,
    ]);

    expect($virement->numero_piece)->not->toBeNull();
    expect($virement->numero_piece)->toStartWith('2025-2026:');
});
