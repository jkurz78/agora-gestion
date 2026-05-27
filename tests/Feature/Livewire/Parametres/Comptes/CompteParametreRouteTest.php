<?php

declare(strict_types=1);

use App\Livewire\SousCategorieList;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
});

// Test [D] : /parametres/comptes répond 200 avec le composant SousCategorieList monté
it('[D] route /parametres/comptes répond 200', function (): void {
    $response = $this->get(route('parametres.comptes.index'));

    $response->assertStatus(200);
    $response->assertSeeLivewire(SousCategorieList::class);
});

// Test [E] : /parametres/sous-categories redirige 301 vers /parametres/comptes
it('[E] route /parametres/sous-categories redirige 301 vers /parametres/comptes', function (): void {
    $response = $this->get('/parametres/sous-categories');

    $response->assertStatus(301);
    $response->assertRedirect('/parametres/comptes');
});
