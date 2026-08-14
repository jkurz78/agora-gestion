<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    // TransactionForm::render() lit Auth::user()->currentRole() — la
    // transaction d'un utilisateur non authentifié ne peut pas s'afficher.
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
});

it('verrouille le formulaire d’une transaction d’acquisition', function (): void {
    Livewire::test(TransactionForm::class)
        ->call('edit', (int) $this->immo->transaction_id)
        ->assertSet('isLockedByImmobilisation', true)
        ->assertSee('provient de l’immobilisation');
});

it('verrouille le formulaire d’une transaction de dotation avec le libellé dédié', function (): void {
    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $dotation = ImmobilisationDotation::where('exercice', 2026)
        ->where('immobilisation_id', $this->immo->id)
        ->firstOrFail();

    Livewire::test(TransactionForm::class)
        ->call('edit', (int) $dotation->transaction_id)
        ->assertSet('isLockedByImmobilisation', true)
        ->assertSet('immobilisationId', (int) $this->immo->id)
        ->assertDontSee('provient de l’immobilisation')
        ->assertSee('dotation aux amortissements de l’immobilisation');
});

it('ne verrouille pas une transaction ordinaire', function (): void {
    $compte6 = Compte::factory()->create(['numero_pcg' => '606', 'classe' => 6]);
    $tx = Transaction::factory()->create();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte6->id,
        'debit' => 100,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', (int) $tx->id)
        ->assertSet('isLockedByImmobilisation', false);
});
