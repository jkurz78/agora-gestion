<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/**
 * C2 (audit) — l'invariant que le docblock d'ImmobilisationPdfController
 * énonçait sans le garantir : « le PDF est un rendu figé de la fiche, il ne
 * doit pas diverger de l'écran ». Depuis la fusion dans
 * PlanAmortissementCalculator::plan(), les deux appelants partagent le même
 * calcul — ce test rend les deux VRAIS chemins (composant Livewire, route
 * PDF réelle) et compare les données de plan qu'ils reçoivent, plutôt que de
 * ré-invoquer le même calcul deux fois.
 */
it('rend le même plan sur l’écran et sur le PDF de la fiche', function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

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

    // Une fiche partiellement dotée : distingue le comptabilisé du prévisionnel
    // dans le plan, exactement ce que l'écran et le PDF doivent afficher pareil.
    Carbon::setTestNow('2027-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');
    Carbon::setTestNow();

    $planEcran = Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo->fresh()])
        ->viewData('plan');

    $planPdf = null;
    View::composer('pdf.immobilisation', function ($view) use (&$planPdf): void {
        $planPdf = $view->getData()['plan'];
    });

    $this->get(route('immobilisations.pdf', $immo))->assertOk();

    expect($planPdf)->not->toBeNull()
        ->and($planPdf)->not->toBeEmpty();

    $normalise = fn (array $plan): array => array_map(
        fn ($ligne): array => [
            'exercice' => $ligne->exercice,
            'moisEcoules' => $ligne->moisEcoules,
            'dotationCentimes' => $ligne->dotationCentimes,
            'cumulCentimes' => $ligne->cumulCentimes,
            'valeurNetteCentimes' => $ligne->valeurNetteCentimes,
            'comptabilisee' => $ligne->comptabilisee,
            'transactionId' => $ligne->transactionId,
        ],
        $plan,
    );

    expect($normalise($planPdf))->toBe($normalise($planEcran));
});
