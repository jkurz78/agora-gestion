<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\RemiseBancaireShow;
use App\Models\RemiseBancaire;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;

require_once __DIR__.'/EcritureGeneratorJournalTest.php';

beforeEach(function () {
    SystemeSeeder::seed();
});

it('estBrouillon reste vrai après enregistrerBrouillon et faux après comptabiliser', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $tiers = tiersJrn();
    $ligne5112 = creerLigne5112SourceJrn($tiers, 80.00, $compte512);
    $sourceTxId = (int) $ligne5112->transaction_id;

    $user = User::factory()->create();

    $remise = RemiseBancaire::create([
        'association_id' => TenantContext::currentId(),
        'numero' => 9001,
        'date' => '2026-05-22',
        'mode_paiement' => ModePaiement::Cheque,
        'compte_cible_id' => $compteBancaire->id,
        'libelle' => 'Remise test comptabilisee_at',
        'saisi_par' => $user->id,
    ]);

    $service = app(RemiseBancaireService::class);

    $service->enregistrerBrouillon($remise, [$sourceTxId]);
    expect($remise->fresh()->comptabilisee_at)->toBeNull();
    $showAvant = new RemiseBancaireShow;
    $showAvant->remise = $remise->fresh();
    expect($showAvant->estBrouillon())->toBeTrue('le bouton Comptabiliser doit rester visible');

    $service->comptabiliser($remise->fresh(), [$sourceTxId]);
    expect($remise->fresh()->comptabilisee_at)->not->toBeNull();
    $showApres = new RemiseBancaireShow;
    $showApres->remise = $remise->fresh();
    expect($showApres->estBrouillon())->toBeFalse();
});
