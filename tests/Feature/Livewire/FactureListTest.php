<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Livewire\FactureList;
use App\Models\Association;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->exercice = app(ExerciceService::class)->current();

    // Register stub routes (real routes will be added in Task 13)
    Route::middleware('web')->name('gestion.')->prefix('gestion')->group(function () {
        Route::get('/factures/{facture}/edit', fn () => '')->name('factures.edit');
        Route::get('/factures/{facture}', fn () => '')->name('factures.show');
    });
});

afterEach(function () {
    TenantContext::clear();
});

it('renders the facture list component', function () {
    Livewire::test(FactureList::class)
        ->assertStatus(200)
        ->assertSee('Nouvelle facture');
});

it('displays existing factures with correct data', function () {
    $tiers = Tiers::factory()->pourRecettes()->create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
        'prenom' => 'Jean',
    ]);

    Facture::create([
        'association_id' => $this->association->id,
        'numero' => 'F-'.$this->exercice.'-0001',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 150.00,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(FactureList::class)
        ->assertSee('F-'.$this->exercice.'-0001')
        ->assertSee('Jean DUPONT')
        ->assertSee('150,00');
});

it('shows correct badges for each statut', function () {
    $tiers = Tiers::factory()->pourRecettes()->create(['association_id' => $this->association->id]);

    // Brouillon
    Facture::create([
        'association_id' => $this->association->id,
        'numero' => null,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    // Validee
    Facture::create([
        'association_id' => $this->association->id,
        'numero' => 'F-'.$this->exercice.'-0001',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 100.00,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    // Annulee
    Facture::create([
        'association_id' => $this->association->id,
        'numero' => 'F-'.$this->exercice.'-0002',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Annulee,
        'tiers_id' => $tiers->id,
        'montant_total' => 50.00,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(FactureList::class)
        ->assertSee('Brouillon')
        ->assertSee('Validée')
        ->assertSee('Annulée');
});

it('creer() without arguments opens the modal and does not redirect', function () {
    Livewire::test(FactureList::class)
        ->call('creer')
        ->assertSet('showCreerModal', true)
        ->assertNoRedirect();
});

it('creer($tiersId) with valid tiers creates the facture and redirects to edit', function () {
    $tiers = Tiers::factory()->pourRecettes()->create(['association_id' => $this->association->id]);

    Livewire::test(FactureList::class)
        ->call('creer', $tiers->id)
        ->assertRedirect();

    expect(Facture::count())->toBe(1);

    $facture = Facture::first();
    expect($facture->statut)->toBe(StatutFacture::Brouillon);
    expect($facture->tiers_id)->toBe($tiers->id);
    expect($facture->numero)->toBeNull();
});

it('creer($invalidId) with non-existent tiers fails validation', function () {
    Livewire::test(FactureList::class)
        ->call('creer', 999999)
        ->assertHasErrors(['newFactureTiersId']);
});

it('deletes a brouillon facture', function () {
    $tiers = Tiers::factory()->pourRecettes()->create(['association_id' => $this->association->id]);

    $facture = Facture::create([
        'association_id' => $this->association->id,
        'numero' => null,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(FactureList::class)
        ->call('supprimer', $facture->id);

    expect(Facture::count())->toBe(0);
});
