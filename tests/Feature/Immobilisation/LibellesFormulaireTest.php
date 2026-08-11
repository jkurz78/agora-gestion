<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

// ── Formulaire de création ───────────────────────────────────────

it('renomme le champ montant en « Montant total de l’acquisition »', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->assertSee('Montant total de l’acquisition');
});

it('affiche une aide sur le champ quantité, sans incidence sur l’amortissement', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->assertSee('suivi de l’inventaire')
        ->assertSee('n’entre pas dans le calcul de l’amortissement');
});

it('affiche le rappel du prix unitaire quand la quantité dépasse 1 et le montant est renseigné', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('quantite', 20)
        ->set('montant', '3000.00')
        ->assertSee('soit 150,00 € l’unité');
});

it('ne montre pas de rappel de prix unitaire pour une quantité de 1', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('quantite', 1)
        ->set('montant', '3000.00')
        ->assertDontSee('l’unité');
});

it('ne montre pas de rappel de prix unitaire tant que le montant est vide', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('quantite', 20)
        ->set('montant', '')
        ->assertDontSee('l’unité');
});

// ── Formulaire d'édition ─────────────────────────────────────────

it('affiche le même libellé et le même rappel de prix unitaire dans le formulaire d’édition', function (): void {
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
        ->assertSee('Montant total de l’acquisition')
        ->assertSee('soit 150,00 € l’unité')
        ->assertSee('suivi de l’inventaire');
});

it('recalcule le rappel de prix unitaire quand la quantité change dans le formulaire d’édition', function (): void {
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
        ->set('quantite', 25)
        ->assertSee('soit 120,00 € l’unité');
});
