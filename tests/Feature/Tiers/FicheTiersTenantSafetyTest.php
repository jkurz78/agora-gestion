<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;

it('retourne 404 si le tiers appartient à une autre association', function (): void {
    // Le bootstrap Pest a créé une asso A et booté son contexte.
    $assoA = Association::find(TenantContext::currentId());
    $userA = User::factory()->create();
    $userA->associations()->attach($assoA->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $assoA->id]);

    // Crée un Tiers dans une asso B
    TenantContext::clear();
    $assoB = Association::factory()->create();
    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);

    // Reboot asso A et tente d'accéder au tiers de B en tant que user A
    TenantContext::clear();
    TenantContext::boot($assoA);

    $this->actingAs($userA)
        ->get('/tiers/'.$tiersB->id)
        ->assertNotFound();
});
