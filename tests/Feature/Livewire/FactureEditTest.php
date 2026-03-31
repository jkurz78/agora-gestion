<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Livewire\FactureEdit;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExerciceService;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = app(ExerciceService::class)->current();

    // Register stub routes (real routes will be added in Task 13)
    Route::middleware('web')->name('gestion.')->prefix('gestion')->group(function () {
        Route::get('/factures', fn () => '')->name('factures');
        Route::get('/factures/{facture}/edit', fn () => '')->name('factures.edit');
        Route::get('/factures/{facture}', fn () => '')->name('factures.show');
    });

    $this->tiers = Tiers::factory()->pourRecettes()->create([
        'nom' => 'Martin',
        'prenom' => 'Sophie',
    ]);

    $this->facture = Facture::create([
        'numero' => null,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);
});

it('renders with facture data', function () {
    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertStatus(200)
        ->assertSee('Brouillon de facture')
        ->assertSee('Sophie Martin');
});

it('shows available transactions for the tiers', function () {
    $compte = CompteBancaire::factory()->create();

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Inscription Yoga',
        'montant_total' => 120.00,
        'compte_id' => $compte->id,
        'date' => now(),
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertSee('Inscription Yoga')
        ->assertSee('120,00');
});

it('toggleTransaction adds a transaction and creates lignes', function () {
    $compte = CompteBancaire::factory()->create();

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Cotisation annuelle',
        'montant_total' => 50.00,
        'compte_id' => $compte->id,
        'date' => now(),
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->call('toggleTransaction', $transaction->id);

    // Transaction should now be linked
    expect($this->facture->fresh()->transactions()->count())->toBe(1);

    // Facture lignes should be created from transaction lignes
    expect(FactureLigne::where('facture_id', $this->facture->id)->count())->toBeGreaterThan(0);
});

it('toggleTransaction removes a transaction and deletes lignes', function () {
    $compte = CompteBancaire::factory()->create();

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Stage ete',
        'montant_total' => 200.00,
        'compte_id' => $compte->id,
        'date' => now(),
    ]);

    // First add the transaction
    $component = Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->call('toggleTransaction', $transaction->id);

    expect($this->facture->fresh()->transactions()->count())->toBe(1);
    expect(FactureLigne::where('facture_id', $this->facture->id)->count())->toBeGreaterThan(0);

    // Now toggle again to remove
    $component->call('toggleTransaction', $transaction->id);

    expect($this->facture->fresh()->transactions()->count())->toBe(0);
    expect(FactureLigne::where('facture_id', $this->facture->id)
        ->where('type', TypeLigneFacture::Montant)
        ->count())->toBe(0);
});

it('valider validates the facture and redirects to show', function () {
    $compte = CompteBancaire::factory()->create();

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 75.00,
        'compte_id' => $compte->id,
        'date' => now(),
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->call('toggleTransaction', $transaction->id)
        ->call('valider')
        ->assertRedirect();

    $facture = $this->facture->fresh();
    expect($facture->statut)->toBe(StatutFacture::Validee);
    expect($facture->numero)->not->toBeNull();
});

it('supprimer deletes the brouillon and redirects to list', function () {
    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->call('supprimer')
        ->assertRedirect(route('gestion.factures'));

    expect(Facture::find($this->facture->id))->toBeNull();
});

it('redirects to show if facture is not brouillon', function () {
    $this->facture->update([
        'statut' => StatutFacture::Validee,
        'numero' => 'F-'.$this->exercice.'-0001',
        'montant_total' => 100.00,
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertRedirect(route('gestion.factures.show', $this->facture));
});
