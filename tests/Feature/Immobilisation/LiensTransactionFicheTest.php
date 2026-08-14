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
 * chaque transaction de dotation ».
 *
 * Recette du 13/08/2026 — la fiche dispatchait edit-transaction pour ouvrir
 * TransactionForm en lecture seule. Cet écran s'est révélé trompeur sur une
 * écriture d'immobilisation (montant à 0, aucune ligne, compte bancaire et
 * bloc paiement vides) : voir EcritureAcquisitionLectureTest pour le détail.
 * La fiche affiche désormais l'écriture elle-même, en lecture seule.
 *
 * La ventilation d'une dotation, elle, reste sur l'écran des dotations
 * (DotationsExercice::ventiler(), qui dispatche toujours edit-transaction) :
 * la fiche montre, l'écran de clôture traite.
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
        ->assertSeeHtml('wire:click="voirEcriture('.$this->immo->transaction_id.')"');
});

it('affiche l’écriture d’acquisition en lecture seule', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('voirEcriture', (int) $this->immo->transaction_id)
        ->assertSet('ecritureTransactionId', (int) $this->immo->transaction_id)
        ->assertNotDispatched('edit-transaction');
});

it('affiche un lien vers chaque transaction de dotation comptabilisée', function (): void {
    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $dotation = ImmobilisationDotation::where('exercice', 2026)
        ->where('immobilisation_id', $this->immo->id)
        ->firstOrFail();

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo->fresh()])
        ->assertSeeHtml('wire:click="voirEcriture('.$dotation->transaction_id.')"');
});

it('affiche l’écriture de dotation en lecture seule', function (): void {
    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $dotation = ImmobilisationDotation::where('exercice', 2026)
        ->where('immobilisation_id', $this->immo->id)
        ->firstOrFail();

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo->fresh()])
        ->call('voirEcriture', (int) $dotation->transaction_id)
        ->assertSet('ecritureTransactionId', (int) $dotation->transaction_id)
        ->assertNotDispatched('edit-transaction');
});

it('laisse la ventilation d’une dotation sur l’écran des dotations', function (): void {
    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    $dotation = ImmobilisationDotation::where('exercice', 2026)
        ->where('immobilisation_id', $this->immo->id)
        ->firstOrFail();

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('ventiler', (int) $dotation->transaction_id)
        ->assertDispatched('edit-transaction', id: (int) $dotation->transaction_id);

    Carbon::setTestNow();
});

it('ne propose aucun lien de dotation quand l’exercice n’a pas encore été doté', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->assertDontSeeHtml('Voir l’écriture de dotation');
});
