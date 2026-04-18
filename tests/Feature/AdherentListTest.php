<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
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

test('adherents page loads successfully', function (): void {
    $this->get('/tiers/adherents')
        ->assertOk()
        ->assertSee('Adhérent');
});

test('legacy /membres redirects to /tiers/adherents', function (): void {
    $this->get('/membres')
        ->assertRedirect('/tiers/adherents');
});
