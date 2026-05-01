<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 5 : FactureEdit refuse prix_unitaire et quantite négatifs.
 *
 * Vérifie que le composant FactureEdit rejette une ligne manuelle dont le prix
 * unitaire ou la quantité est négatif, avec le message standardisé défini dans
 * RefusesMontantNegatif.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Enums\StatutFacture;
use App\Livewire\Concerns\MontantValidation;
use App\Livewire\FactureEdit;
use App\Models\Association;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Routes stub nécessaires pour FactureEdit (redirect interne)
    if (! Route::has('facturation.factures.show')) {
        Route::middleware('web')->name('facturation.')->prefix('facturation')->group(function () {
            Route::get('/factures', fn () => '')->name('factures');
            Route::get('/factures/{facture}', fn () => '')->name('factures.show');
        });
    }

    $this->exercice = app(ExerciceService::class)->current();

    $this->tiers = Tiers::factory()->pourRecettes()->create([
        'association_id' => $this->association->id,
    ]);

    $this->facture = Facture::create([
        'association_id' => $this->association->id,
        'numero' => null,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('facture_edit_refuse_prix_unitaire_negatif_avec_message_standard', function (): void {
    $component = Livewire::test(FactureEdit::class, ['facture' => $this->facture]);

    $component->call('ouvrirFormLigneManuelle')
        ->set('nouvelleLigneMontantLibelle', 'Prestation test')
        ->set('nouvelleLigneMontantPrixUnitaire', '-50')
        ->set('nouvelleLigneMontantQuantite', '1')
        ->call('ajouterLigneManuelle');

    $component->assertHasErrors(['nouvelleLigneMontantPrixUnitaire']);

    expect($component->errors()->first('nouvelleLigneMontantPrixUnitaire'))
        ->toBe(MontantValidation::MESSAGE);
});

it('facture_edit_refuse_quantite_negative_avec_message_standard', function (): void {
    $component = Livewire::test(FactureEdit::class, ['facture' => $this->facture]);

    $component->call('ouvrirFormLigneManuelle')
        ->set('nouvelleLigneMontantLibelle', 'Prestation test')
        ->set('nouvelleLigneMontantPrixUnitaire', '100')
        ->set('nouvelleLigneMontantQuantite', '-2')
        ->call('ajouterLigneManuelle');

    $component->assertHasErrors(['nouvelleLigneMontantQuantite']);

    expect($component->errors()->first('nouvelleLigneMontantQuantite'))
        ->toBe(MontantValidation::MESSAGE);
});
