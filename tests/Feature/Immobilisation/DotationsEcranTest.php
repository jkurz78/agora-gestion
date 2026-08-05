<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
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

it('affiche l’aperçu et génère les dotations', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertSee('IM00001')
        ->assertSee('600,00')
        ->call('genererTout')
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    Carbon::setTestNow();
});

it('bloque la génération sur un exercice non terminé', function (): void {
    Carbon::setTestNow('2027-03-01');

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('genererTout');

    expect(ImmobilisationDotation::count())->toBe(0);

    Carbon::setTestNow();
});

it('signale un écart et le recalcule', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    $this->immo->update(['duree_mois' => 30]);

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertSee('Écart')
        ->call('recalculer', (int) $this->immo->id)
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('1200.00');

    Carbon::setTestNow();
});
