<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\DotationService;
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

it('affiche le plan d’amortissement complet sur toute la durée', function (): void {
    $composant = Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo]);

    $plan = $composant->viewData('plan');

    expect($plan)->toHaveCount(5)
        ->and($plan[0]['exercice'])->toBe(2026)
        ->and($plan[0]['dotationCentimes'])->toBe(60000)
        ->and($plan[4]['exercice'])->toBe(2030)
        ->and($plan[4]['cumulCentimes'])->toBe(300000)
        ->and($plan[4]['valeurNetteCentimes'])->toBe(0);
});

it('distingue les exercices comptabilisés des projections', function (): void {
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    $plan = Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo->fresh()])
        ->viewData('plan');

    expect($plan[0]['comptabilisee'])->toBeTrue()
        ->and($plan[1]['comptabilisee'])->toBeFalse();
});
