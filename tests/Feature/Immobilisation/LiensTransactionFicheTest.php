<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * C1 — spec § 7.2 : « un lien vers la transaction d'acquisition, un lien vers
 * chaque transaction de dotation ». Même patron que
 * DotationsExercice::ventiler() : la fiche dispatche edit-transaction,
 * TransactionForm (monté dans layouts.app-sidebar) l'ouvre et l'affiche en
 * lecture seule (isLockedByImmobilisation), cf. VerrouTransactionTest.
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
});

it('affiche un lien vers la transaction d’acquisition', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->assertSeeHtml('wire:click="ouvrirTransaction('.$this->immo->transaction_id.')"');
});

it('dispatche edit-transaction avec l’identifiant de la transaction d’acquisition', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('ouvrirTransaction', (int) $this->immo->transaction_id)
        ->assertDispatched('edit-transaction', id: (int) $this->immo->transaction_id);
});

it('affiche un lien vers chaque transaction de dotation comptabilisée', function (): void {
    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $dotation = ImmobilisationDotation::where('exercice', 2026)
        ->where('immobilisation_id', $this->immo->id)
        ->firstOrFail();

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo->fresh()])
        ->assertSeeHtml('wire:click="ouvrirTransaction('.$dotation->transaction_id.')"');
});

it('dispatche edit-transaction avec l’identifiant de la transaction de dotation', function (): void {
    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $dotation = ImmobilisationDotation::where('exercice', 2026)
        ->where('immobilisation_id', $this->immo->id)
        ->firstOrFail();

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo->fresh()])
        ->call('ouvrirTransaction', (int) $dotation->transaction_id)
        ->assertDispatched('edit-transaction', id: (int) $dotation->transaction_id);
});

it('ne propose aucun lien de dotation quand l’exercice n’a pas encore été doté', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->assertDontSeeHtml('Voir l’écriture de dotation');
});
