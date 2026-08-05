<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

// ── Création ─────────────────────────────────────────────────────

it('propose une option « Autre durée… » en plus des durées usuelles', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->assertSee('Autre durée…')
        ->assertSee('5 ans'); // les durées usuelles restent proposées
});

it('révèle un champ libre en mois quand « Autre durée… » est choisi', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->assertSet('dureeChoix', '60')
        ->set('dureeChoix', 'autre')
        ->assertSet('dureeChoix', 'autre')
        // La sélection d'une durée usuelle, elle, écrase directement duree_mois.
        ->set('dureeChoix', '84')
        ->assertSet('duree_mois', 84);
});

it('crée une immobilisation avec une durée libre de 42 mois (bail restant à courir)', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Agencement du local loué')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '5000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('dureeChoix', 'autre')
        ->set('duree_mois', 42)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    expect($immo->duree_mois)->toBe(42)
        ->and($immo->duree_label)->toBe('42 mois');
});

it('affiche la durée libre dans le livre des immobilisations', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel subventionné',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '2000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 30,
        modePaiement: null,
        compteTresorerie: null,
    );

    Livewire::test(ImmobilisationIndex::class)->assertSee('30 mois');
});

// ── Édition ──────────────────────────────────────────────────────

it('initialise le sélecteur sur « Autre durée… » quand la fiche porte une durée non usuelle', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel subventionné',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '2000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 30,
        modePaiement: null,
        compteTresorerie: null,
    );

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->call('ouvrirEdition')
        ->assertSet('dureeChoix', 'autre')
        ->assertSet('duree_mois', 30)
        ->assertSee('Autre durée…');
});

it('initialise le sélecteur sur la durée usuelle correspondante quand elle en fait partie', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
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

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->call('ouvrirEdition')
        ->assertSet('dureeChoix', '60');
});

it('modifie une fiche vers une durée libre de 30 mois (convention de financement)', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
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

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])
        ->call('ouvrirEdition')
        ->set('dureeChoix', 'autre')
        ->set('duree_mois', 30)
        ->call('enregistrerModification')
        ->assertHasNoErrors();

    $fresh = $immo->fresh();
    expect($fresh->duree_mois)->toBe(30)
        ->and($fresh->duree_label)->toBe('30 mois');
});

it('affiche la durée libre sur la fiche', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Agencement du local loué',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '5000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 42,
        modePaiement: null,
        compteTresorerie: null,
    );

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo])->assertSee('42 mois');
});
