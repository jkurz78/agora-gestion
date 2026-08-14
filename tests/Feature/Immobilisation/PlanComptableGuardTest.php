<?php

declare(strict_types=1);

use App\Livewire\PlanComptable;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Bug 3 — l'écran Plan comptable vient d'ouvrir la classe 2 à la
 * suppression. Tant qu'aucune dotation n'a été générée, le compte
 * d'amortissement 281X d'une fiche ne porte aucune écriture : rien
 * n'empêchait sa suppression, ni celle du compte d'immobilisation lui-même.
 */
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

it('refuse de supprimer le compte d’immobilisation référencé par compte_id', function (): void {
    $compte = Compte::ofNumero('2188');

    Livewire::test(PlanComptable::class)
        ->call('delete', $compte->id)
        ->assertSet('flashType', 'danger')
        ->assertSet('flashMessage', 'Suppression impossible : ce compte est utilisé par une immobilisation.');

    expect(Compte::find($compte->id))->not->toBeNull();
});

it('refuse de supprimer le compte d’amortissement référencé par compte_amortissement_id', function (): void {
    $compte = Compte::ofNumero('28188');

    Livewire::test(PlanComptable::class)
        ->call('delete', $compte->id)
        ->assertSet('flashType', 'danger')
        ->assertSet('flashMessage', 'Suppression impossible : ce compte est utilisé par une immobilisation.');

    expect(Compte::find($compte->id))->not->toBeNull();
});

it('laisse supprimable un compte de classe 2 non utilisé par une fiche', function (): void {
    $compte = Compte::ofNumero('2183');

    Livewire::test(PlanComptable::class)
        ->call('delete', $compte->id);

    expect(Compte::withTrashed()->find($compte->id))->toBeNull();
});

it('marque 6811 comme compte système, sans toucher aux 21XX/281XX du kit', function (): void {
    expect(Compte::ofNumero('6811')->est_systeme)->toBeTrue()
        ->and(Compte::ofNumero('2188')->est_systeme)->toBeFalse()
        ->and(Compte::ofNumero('28188')->est_systeme)->toBeFalse();
});

it('refuse la suppression de 6811, désormais compte système', function (): void {
    $compte = Compte::ofNumero('6811');

    Livewire::test(PlanComptable::class)
        ->call('delete', $compte->id)
        ->assertSet('flashType', 'danger');

    expect(Compte::find($compte->id))->not->toBeNull();
});

/**
 * Anomalie 1 (audit) — même trou que la suppression, côté renumérotation : le
 * compte d'amortissement 281X d'une fiche fraîche ne porte encore aucune
 * écriture (aucune dotation générée), donc la garde "porte des écritures"
 * ne le protège pas. Le compte d'immobilisation, lui, porte déjà la ligne de
 * la transaction d'acquisition — la garde immobilisation doit donc être
 * vérifiée avant celle des écritures pour donner la raison la plus
 * spécifique, comme le fait déjà delete().
 */
it('refuse de renuméroter le compte d’immobilisation référencé par compte_id', function (): void {
    $compte = Compte::ofNumero('2188');

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '2199')
        ->assertSet('flashType', 'danger')
        ->assertSet('flashMessage', 'Renumérotation impossible : ce compte est utilisé par une immobilisation.')
        ->assertReturned(false);

    expect($compte->fresh()->numero_pcg)->toBe('2188');
});

it('refuse de renuméroter le compte d’amortissement référencé par compte_amortissement_id', function (): void {
    $compte = Compte::ofNumero('28188');

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '28199')
        ->assertSet('flashType', 'danger')
        ->assertSet('flashMessage', 'Renumérotation impossible : ce compte est utilisé par une immobilisation.')
        ->assertReturned(false);

    expect($compte->fresh()->numero_pcg)->toBe('28188');
});
