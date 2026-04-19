<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\Dashboard;
use App\Models\Association;
use App\Models\User;
use Livewire\Livewire;

it('computes tenant kpis for super-admin dashboard', function () {
    // The backfill migration inserts 1 default 'actif' association in a fresh DB.
    $baseline = Association::where('statut', 'actif')->count();

    Association::factory()->count(2)->create(['statut' => 'actif']);
    Association::factory()->create(['statut' => 'suspendu']);
    Association::factory()->create(['statut' => 'archive']);

    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);

    Livewire::actingAs($superAdmin)
        ->test(Dashboard::class)
        ->assertViewHas('kpiActifs', $baseline + 2)
        ->assertViewHas('kpiSuspendus', 1)
        ->assertViewHas('kpiArchives', 1);
});
