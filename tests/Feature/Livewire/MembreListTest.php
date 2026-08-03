<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Livewire\AdherentList;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('affiche bi-check-lg Bootstrap Icon pour un membre avec cotisation pointée', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'statut_reglement' => StatutReglement::Pointe,
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'transaction_id' => $tx->id,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('n\'affiche pas le caractère unicode ✓', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'statut_reglement' => StatutReglement::Pointe,
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'transaction_id' => $tx->id,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertDontSee('✓');
});

it('affiche un bouton bi-clock-history lié aux transactions du membre', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'transaction_id' => $tx->id,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('bi bi-clock-history')
        ->assertSeeHtml('href="'.route('tiers.transactions', $tiers->id).'"');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);
    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'transaction_id' => $tx->id,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
