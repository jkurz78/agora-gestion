<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\Transaction;
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
// Test 1 : NDF Soumise + abandon_creance_propose = true → bandeau "proposé en attente"
// ---------------------------------------------------------------------------

it('show: NDF soumise avec abandon_creance_propose=true affiche le bandeau proposé en attente', function () {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'abandon_creance_propose' => true,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSee('Don par abandon de créance proposé — en attente de traitement');
});

// ---------------------------------------------------------------------------
// Test 2 : NDF DonParAbandonCreances avec donTransaction → bandeau acté + date + montant
// ---------------------------------------------------------------------------

it('show: NDF DonParAbandonCreances affiche le bandeau acté avec date et montant', function () {
    $donTransaction = Transaction::factory()->create([
        'association_id' => $this->asso->id,
        'date' => '2026-04-15',
        'montant_total' => 120.50,
    ]);

    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutNoteDeFrais::DonParAbandonCreances->value,
        'don_transaction_id' => $donTransaction->id,
        'abandon_creance_propose' => true,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSee('Don par abandon de créance — acté le 15/04/2026')
        ->assertSee('120,50');
});

// ---------------------------------------------------------------------------
// Test 3 : NDF sans intention (abandon_creance_propose=false, statut Brouillon)
//          → aucun bandeau abandon affiché
// ---------------------------------------------------------------------------

it('show: NDF brouillon sans abandon_creance_propose n\'affiche pas de bandeau abandon', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'abandon_creance_propose' => false,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertDontSee('Don par abandon de créance');
});

// ---------------------------------------------------------------------------
// Test 4 : NDF Rejetée avec abandon_creance_propose=true → pas le bandeau "proposé en attente"
// ---------------------------------------------------------------------------

it('show: NDF rejetée avec abandon_creance_propose=true n\'affiche pas le bandeau proposé', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'abandon_creance_propose' => true,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertDontSee('Don par abandon de créance proposé — en attente de traitement');
});
