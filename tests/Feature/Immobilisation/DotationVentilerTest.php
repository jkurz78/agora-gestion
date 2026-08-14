<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * R6 — la dotation aux amortissements est comptabilisée en journal OD, exclu
 * de la liste « Recettes & dépenses ». Sans point d'entrée dédié, sa ligne
 * 6811 (compte de charge) est inatteignable en édition, donc impossible à
 * ventiler sur les opérations. Ce fichier couvre le bouton « Ventiler » de
 * l'écran des dotations et le parcours complet qu'il débloque.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $this->dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
});

it('porte l’identifiant de la transaction de dotation sur la ligne d’aperçu', function (): void {
    $ligne = app(DotationService::class)->apercu(2026)
        ->first(fn ($l) => (int) $l->immobilisation->id === (int) $this->immo->id);

    expect($ligne->transactionId)->toBe((int) $this->dotation->transaction_id);
});

it('ne porte aucun identifiant de transaction quand la dotation n’est pas encore comptabilisée', function (): void {
    $ligne = app(DotationService::class)->apercu(2027)
        ->first(fn ($l) => (int) $l->immobilisation->id === (int) $this->immo->id);

    expect($ligne->transactionId)->toBeNull();
});

it('dispatche edit-transaction avec l’identifiant de la transaction de dotation', function (): void {
    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('ventiler', (int) $this->dotation->transaction_id)
        ->assertDispatched('edit-transaction', id: (int) $this->dotation->transaction_id);
});

it('affiche un bouton Ventiler sur les dotations déjà comptabilisées', function (): void {
    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertSeeHtml('wire:click="ventiler('.$this->dotation->transaction_id.')"');
});

it('permet de ventiler la ligne 6811 d’une dotation ouverte depuis l’écran des dotations', function (): void {
    $operation = Operation::factory()->create();
    $transactionId = (int) $this->dotation->transaction_id;

    // Le bouton de l'écran des dotations dispatche l'évènement qu'écoute TransactionForm.
    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('ventiler', $transactionId)
        ->assertDispatched('edit-transaction', id: $transactionId);

    // TransactionForm est monté sur le même layout et réagit à cet évènement — on
    // rejoue son effet directement, comme le fait déjà VerrouTransactionTest.
    $form = Livewire::test(TransactionForm::class)->call('edit', $transactionId);

    $ligne = TransactionLigne::where('transaction_id', $transactionId)
        ->whereHas('compte', fn ($q) => $q->where('numero_pcg', '6811'))
        ->firstOrFail();

    $form->call('ouvrirVentilation', (int) $ligne->id)
        ->set('affectations', [[
            'operation_id' => (string) $operation->id,
            'seance' => '',
            'montant' => (string) $ligne->montant,
            'notes' => '',
        ]])
        ->call('saveVentilation')
        ->assertHasNoErrors();

    $affectation = TransactionLigneAffectation::where('transaction_ligne_id', $ligne->id)->first();

    expect(TransactionLigneAffectation::where('transaction_ligne_id', $ligne->id)->count())->toBe(1)
        ->and($affectation?->operation_id)->toBe((int) $operation->id);
});
