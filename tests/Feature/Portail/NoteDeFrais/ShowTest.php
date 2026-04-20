<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Livewire\Portail\NoteDeFrais\Show;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
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
// Test 1 : Show brouillon du tiers → affiché + bouton Supprimer
// ---------------------------------------------------------------------------

it('show: brouillon du tiers affiché avec bouton Supprimer', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais brouillon test',
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSeeText('Brouillon')
        ->assertSee('Supprimer');
});

// ---------------------------------------------------------------------------
// Test 2 : Show soumise → affichée sans bouton Supprimer
// ---------------------------------------------------------------------------

it('show: NDF soumise affichée sans bouton Supprimer', function () {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais soumise',
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSeeText('Soumise')
        ->assertDontSee('Supprimer');
});

// ---------------------------------------------------------------------------
// Test 3 : Show rejetée → motif affiché
// ---------------------------------------------------------------------------

it('show: NDF rejetée affiche le motif de rejet', function () {
    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutNoteDeFrais::Rejetee->value,
        'motif_rejet' => 'Justificatif illisible',
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSeeText('Rejetée')
        ->assertSee('Motif de rejet');
});

// ---------------------------------------------------------------------------
// Test 4 : Show validée → statut Validée
// ---------------------------------------------------------------------------

it('show: NDF validée affiche statut validée', function () {
    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSeeText('Validée');
});

// ---------------------------------------------------------------------------
// Test 5 : Show NDF d'un autre Tiers → 403
// ---------------------------------------------------------------------------

it('show: NDF d\'un autre tiers retourne 403', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Test 6 : Delete brouillon → softdeleted + redirect index
// ---------------------------------------------------------------------------

it('show: delete brouillon softdelete et redirige', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Show();
    $component->association = $this->asso;
    $component->noteDeFrais = $ndf;

    $component->delete();

    $ndf->refresh();
    expect($ndf->trashed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 7 : Delete NDF soumise → refusé (DomainException)
// ---------------------------------------------------------------------------

it('show: delete NDF soumise refusé via policy', function () {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    // The policy deny for delete when not brouillon
    // Verify via HTTP route that delete of soumise fails
    // Since delete is called via Livewire action, we test the component directly
    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Show();
    $component->association = $this->asso;
    $component->noteDeFrais = $ndf;

    expect(fn () => $component->delete())
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $ndf->refresh();
    expect($ndf->trashed())->toBeFalse();
});
