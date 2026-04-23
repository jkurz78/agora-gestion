<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
});

it('redirige vers portail.home avec flash si pour_depenses=false (mono)', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
        'pour_recettes' => true,
    ]);

    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get('/portail/notes-de-frais')
        ->assertRedirect('/portail/')
        ->assertSessionHas('portail.info');
});

it('laisse passer si pour_depenses=true (mono)', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => true,
    ]);

    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get('/portail/notes-de-frais')->assertStatus(200);
});

it('redirige vers le home slug-first (multi)', function () {
    $asso = Association::factory()->create(['slug' => 'svs']);
    Association::factory()->create(['slug' => 'autre']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
        'pour_recettes' => true,
    ]);

    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get('/svs/portail/notes-de-frais')
        ->assertRedirect('/svs/portail/')
        ->assertSessionHas('portail.info');
});
