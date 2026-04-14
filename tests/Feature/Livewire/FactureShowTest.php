<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Livewire\FactureShow;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RemiseBancaire;
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
        Route::get('/factures/{facture}/pdf', fn () => '')->name('factures.pdf');
    });

    $this->tiers = Tiers::factory()->pourRecettes()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'adresse_ligne1' => '12 rue des Lilas',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $this->compte = CompteBancaire::factory()->create();

    $this->facture = Facture::create([
        'numero' => 'F-'.$this->exercice.'-0001',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $this->tiers->id,
        'compte_bancaire_id' => $this->compte->id,
        'montant_total' => 150.00,
        'conditions_reglement' => 'Paiement a reception',
        'mentions_legales' => 'Association loi 1901',
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    FactureLigne::create([
        'facture_id' => $this->facture->id,
        'type' => TypeLigneFacture::Texte,
        'libelle' => 'Prestations associatives',
        'montant' => null,
        'ordre' => 1,
    ]);

    FactureLigne::create([
        'facture_id' => $this->facture->id,
        'type' => TypeLigneFacture::Montant,
        'libelle' => 'Cotisation annuelle',
        'montant' => 100.00,
        'ordre' => 2,
    ]);

    FactureLigne::create([
        'facture_id' => $this->facture->id,
        'type' => TypeLigneFacture::Montant,
        'libelle' => 'Frais de dossier',
        'montant' => 50.00,
        'ordre' => 3,
    ]);
});

it('renders with all facture data', function () {
    Livewire::test(FactureShow::class, ['facture' => $this->facture])
        ->assertStatus(200)
        ->assertSee('F-'.$this->exercice.'-0001')
        ->assertSee($this->facture->date->format('d/m/Y'))
        ->assertSee('Marie DUPONT')
        ->assertSee('Prestations associatives')
        ->assertSee('Cotisation annuelle')
        ->assertSee('Frais de dossier')
        ->assertSee('12 rue des Lilas')
        ->assertSee('75001')
        ->assertSee('Paris');
});

it('shows montant regle = 0 when no remise', function () {
    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 150.00,
        'compte_id' => $this->compte->id,
        'date' => now(),
        'remise_id' => null,
    ]);

    $this->facture->transactions()->attach($transaction->id);

    Livewire::test(FactureShow::class, ['facture' => $this->facture])
        ->assertStatus(200)
        ->assertSee('0,00');
});

it('shows montant regle correctly when transactions have remise_id', function () {
    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $this->compte->id,
        'libelle' => 'Remise test',
        'saisi_par' => $this->user->id,
    ]);

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 150.00,
        'compte_id' => $this->compte->id,
        'date' => now(),
        'remise_id' => $remise->id,
    ]);

    $this->facture->transactions()->attach($transaction->id);

    Livewire::test(FactureShow::class, ['facture' => $this->facture])
        ->assertStatus(200)
        ->assertSeeInOrder(['Montant réglé', '150,00']);
});

it('shows badge Acquittee when fully paid', function () {
    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => $this->compte->id,
        'libelle' => 'Remise test',
        'saisi_par' => $this->user->id,
    ]);

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 150.00,
        'compte_id' => $this->compte->id,
        'date' => now(),
        'remise_id' => $remise->id,
        'statut_reglement' => 'recu', // v3: montantRegle() checks statut_reglement
    ]);

    $this->facture->transactions()->attach($transaction->id);

    Livewire::test(FactureShow::class, ['facture' => $this->facture])
        ->assertStatus(200)
        ->assertSee('Acquittée');
});

it('enregistre un règlement chèque via le bouton unifié', function () {
    $compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
    $tiers = Tiers::factory()->create();
    $exercice = now()->month >= 9 ? now()->year : now()->year - 1;

    $facture = Facture::create([
        'numero' => 'F-2026-0099',
        'date' => now(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 120.00,
        'saisi_par' => $this->user->id,
        'exercice' => $exercice,
    ]);

    $transaction = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compteCreances->id,
        'montant_total' => 120.00,
        'mode_paiement' => ModePaiement::Cheque,
    ]);

    $facture->transactions()->attach($transaction->id);

    Livewire::test(FactureShow::class, ['facture' => $facture])
        ->set('selectedTransactionIds', [$transaction->id])
        ->call('enregistrerReglement')
        ->assertHasNoErrors();

    $transaction->refresh();
    expect($transaction->statut_reglement->value)->toBe('recu');
    // Chèque reste sur le compte système (remise ultérieure)
    expect($transaction->compte_id)->toBe($compteCreances->id);
});


it('redirects to edit if facture is brouillon', function () {
    $brouillon = Facture::create([
        'numero' => null,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(FactureShow::class, ['facture' => $brouillon])
        ->assertRedirect(route('facturation.factures.edit', $brouillon));
});
