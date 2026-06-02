<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RapprochementBancaireService;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;

require_once __DIR__.'/EcritureGeneratorJournalTest.php';

beforeEach(function () {
    SystemeSeeder::seed();
});

it('pourDepotRapprochement crée un dépôt 512X/5112 lettré pour une ligne 5112 source', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $t1 = creerCreanceJrn(80.00);
    $t1->update(['compte_id' => $compteBancaire->id, 'mode_paiement' => ModePaiement::Cheque->value]);
    app(ReglementOperationService::class)->encaisserSiNonEncaisse($t1->fresh());
    $t2 = app(ReglementOperationService::class)->trouverEncaissementT2($t1->fresh());
    $compte5112 = compteSystemeJrn('5112');
    $ligne5112 = $t2->lignes->firstWhere('compte_id', $compte5112->id);

    $depot = app(EcritureGenerator::class)->pourDepotRapprochement(
        ligne5112Source: $ligne5112,
        compteCible512: $compte512,
        mode: ModePaiement::Cheque,
        date: new DateTimeImmutable('2026-05-28'),
        libelle: 'Dépôt rapprochement chèque',
    );

    expect($depot->journal->value)->toBe('banque');
    expect($depot->compte_id)->toBeNull();
    $ligne512Depot = $depot->lignes->firstWhere('compte_id', $compte512->id);
    expect((float) $ligne512Depot->debit)->toBe(80.00);
    expect($ligne5112->fresh()->lettrage_code)->not->toBeNull();
});

it('pointer un chèque loose en attente génère un dépôt et meut le solde ; dépointer le supprime', function () {
    config(['compta.use_partie_double' => true]);
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();

    $t1 = creerCreanceJrn(80.00);
    $t1->update(['compte_id' => $compteBancaire->id, 'mode_paiement' => ModePaiement::Cheque->value]);

    $rappro = RapprochementBancaire::create([
        'association_id' => TenantContext::currentId(),
        'compte_id' => $compteBancaire->id, 'date_fin' => '2026-05-31',
        'solde_ouverture' => 0.0, 'solde_fin' => 80.0,
        'statut' => StatutRapprochement::EnCours->value, 'saisi_par' => userIdJrn(),
    ]);
    $service = app(RapprochementBancaireService::class);

    // Pointage → dépôt généré, solde = 80
    $service->toggleTransaction($rappro->fresh(), 'recette', (int) $t1->id);
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(80.0);
    // Le dépôt 4b est la transaction journal=banque, remise=null, rapprochement!=null
    // qui possède une ligne 512X (bancaire physique) au débit
    $depotCount = Transaction::where('journal', 'banque')->whereNull('remise_id')
        ->whereNotNull('rapprochement_id')
        ->whereHas('lignes', fn ($q) => $q->where('debit', '>', 0)->whereHas('compte', fn ($c) => $c->bancaires()))
        ->count();
    expect($depotCount)->toBe(1);

    // Dépointage → dépôt supprimé, solde = 0, T2 (encaissement) subsiste
    $service->toggleTransaction($rappro->fresh(), 'recette', (int) $t1->id);
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(0.0);
    $depotCountApres = Transaction::where('journal', 'banque')->whereNull('remise_id')
        ->whereHas('lignes', fn ($q) => $q->where('debit', '>', 0)->whereHas('compte', fn ($c) => $c->bancaires()))
        ->count();
    expect($depotCountApres)->toBe(0);
    $t2 = app(ReglementOperationService::class)->trouverEncaissementT2($t1->fresh());
    expect($t2)->not->toBeNull();
});
