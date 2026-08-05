<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Exceptions\Immobilisation\SuppressionInterditeException;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
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

it('supprime la fiche et sa transaction d’acquisition', function (): void {
    $transactionId = (int) $this->immo->transaction_id;

    app(ImmobilisationService::class)->supprimer($this->immo);

    expect(Immobilisation::find($this->immo->id))->toBeNull()
        ->and(Immobilisation::withTrashed()->find($this->immo->id)?->trashed())->toBeTrue()
        ->and(Transaction::find($transactionId))->toBeNull()
        ->and(Transaction::withTrashed()->find($transactionId)?->trashed())->toBeTrue();
});

it('refuse la suppression si des dotations ont déjà été générées', function (): void {
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    expect(fn () => app(ImmobilisationService::class)->supprimer($this->immo->fresh()))
        ->toThrow(SuppressionInterditeException::class);

    expect(Immobilisation::find($this->immo->id))->not->toBeNull();
});

it('refuse la suppression si l’exercice de la transaction d’acquisition est clôturé', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    expect(fn () => app(ImmobilisationService::class)->supprimer($this->immo))
        ->toThrow(ExerciceCloturedException::class);

    expect(Immobilisation::find($this->immo->id))->not->toBeNull();
});

it('supprime une fiche depuis la fiche et redirige vers le livre', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('supprimer')
        ->assertRedirect(route('immobilisations.index'));

    expect(Immobilisation::find($this->immo->id))->toBeNull();
});

it('affiche un message d’erreur et reste sur la fiche si la suppression est refusée', function (): void {
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo->fresh()])
        ->call('supprimer')
        ->assertNoRedirect();

    expect(Immobilisation::find($this->immo->id))->not->toBeNull();
});
