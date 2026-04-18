<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->unonboarded()->create([
        'nom' => 'Mon Association Test',
        'wizard_current_step' => 1,
    ]);
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

it('affiche le nom de l\'association dans la navbar onboarding', function () {
    $this->actingAs($this->admin)
        ->get('/onboarding')
        ->assertOk()
        ->assertSee('Onboarding de Mon Association Test', false);
});
