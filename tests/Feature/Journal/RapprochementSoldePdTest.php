<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RapprochementBancaireService;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;

require_once __DIR__.'/EcritureGeneratorJournalTest.php';

beforeEach(function () {
    SystemeSeeder::seed();
    config(['compta.use_partie_double' => true]);
});

it('pointer une remise comptabilisée meut le solde pointé du montant du dépôt (PD)', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $tiers = tiersJrn();
    $ligne5112 = creerLigne5112SourceJrn($tiers, 80.00, $compte512);
    $sourceTxId = (int) $ligne5112->transaction_id;
    // Réalisme : un chèque de séance porte le CompteBancaire en compte_id (legacy) —
    // c'est ce qui le rend visible/rapprochable à l'écran (cf. RapprochementDetail).
    App\Models\Transaction::where('id', $sourceTxId)->update(['compte_id' => $compteBancaire->id]);

    $remise = RemiseBancaire::create([
        'association_id' => TenantContext::currentId(),
        'numero' => 9003, 'date' => '2026-05-26',
        'mode_paiement' => ModePaiement::Cheque,
        'compte_cible_id' => $compteBancaire->id,
        'libelle' => 'Remise solde PD', 'saisi_par' => userIdJrn(),
    ]);
    app(RemiseBancaireService::class)->comptabiliser($remise, [$sourceTxId]);

    $rappro = RapprochementBancaire::create([
        'association_id' => TenantContext::currentId(),
        'compte_id' => $compteBancaire->id,
        'date_fin' => '2026-05-31',
        'solde_ouverture' => 0.0,
        'solde_fin' => 80.0,
        'statut' => StatutRapprochement::EnCours->value,
        'saisi_par' => userIdJrn(),
    ]);

    $service = app(RapprochementBancaireService::class);
    expect($service->calculerSoldePointage($rappro))->toBe(0.0);

    // Pointer la remise → la T4 (porteuse du 512X) reçoit rapprochement_id
    $service->toggleTransaction($rappro->fresh(), 'remise', (int) $remise->id);
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(80.0);

    // Dépointer → revient
    $service->toggleTransaction($rappro->fresh(), 'remise', (int) $remise->id);
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(0.0);
});
