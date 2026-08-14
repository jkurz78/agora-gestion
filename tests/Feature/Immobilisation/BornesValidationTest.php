<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Audit robustesse — point 3 : les bornes de validation Livewire doivent
 * refuser proprement ce que les colonnes SQL de
 * database/migrations/2026_08_04_100001_create_immobilisations_tables.php
 * refuseraient brutalement — quantite (unsignedInteger, max 4294967295) et
 * montant_acquisition (decimal(10,2), max 99999999.99). libelle (max:255) et
 * duree_mois (max:600, sous les 65535 de l'unsignedSmallInteger) étaient déjà
 * correctement bornés.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('refuse une quantité au-delà du plafond unsignedInteger, avec un message français', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel')
        ->set('quantite', 4294967296)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '1000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasErrors(['quantite' => 'max']);

    expect(Immobilisation::count())->toBe(0);
});

it('refuse un montant au-delà du plafond decimal(10,2), avec un message français', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '100000000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasErrors(['montant' => 'max']);

    expect(Immobilisation::count())->toBe(0);
});

it('accepte le montant maximal représentable en decimal(10,2)', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '99999999.99')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasNoErrors();

    expect(Immobilisation::count())->toBe(1);
});

it('refuse depuis la fiche une quantité au-delà du plafond unsignedInteger', function (): void {
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
        ->set('quantite', 4294967296)
        ->call('enregistrerModification')
        ->assertHasErrors(['quantite' => 'max']);

    expect($immo->fresh()->quantite)->toBe(20);
});
