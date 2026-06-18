<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\VirementInterneService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// VirementInterneService::create — guard passe sur le happy path
// ---------------------------------------------------------------------------

it('VirementInterneService::create() avec PD active → guard passe (virement est équilibré)', function () {
    Config::set('compta.use_partie_double', true);

    $cb1 = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $cb2 = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    BancairesSeeder::seed();

    $service = app(VirementInterneService::class);

    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 500.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
        'reference' => 'VIR-GUARD-001',
    ]);

    $transaction = Transaction::where('virement_interne_id', $virement->id)->first();

    // La transaction existe et est équilibrée : le guard est passé sans exception
    expect($transaction)->not->toBeNull();
    expect((bool) $transaction->equilibree)->toBeTrue();
});

// ---------------------------------------------------------------------------
// VirementInterneService::create — PD toujours actif, même sans flag explicite
// ---------------------------------------------------------------------------

it('VirementInterneService::create() sans flag explicite → PD toujours générée', function () {
    $cb1 = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $cb2 = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    BancairesSeeder::seed();

    $service = app(VirementInterneService::class);

    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 300.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
        'reference' => 'VIR-GUARD-002',
    ]);

    $transaction = Transaction::where('virement_interne_id', $virement->id)->first();
    expect($transaction)->not->toBeNull();
    expect((bool) $transaction->equilibree)->toBeTrue();
});
