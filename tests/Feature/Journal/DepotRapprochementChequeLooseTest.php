<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RapprochementBancaireService;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;

require_once __DIR__.'/EcritureGeneratorJournalTest.php';

beforeEach(function () {
    SystemeSeeder::seed();
});

it('pointer un chèque loose (créance T2 séparée) crée une remise auto_generee avec T4, dépointer supprime la remise', function () {
    config(['compta.use_partie_double' => true]);
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();

    // T1 créance en_attente chèque
    $t1 = creerCreanceJrn(80.00);
    $t1->update(['compte_id' => $compteBancaire->id, 'mode_paiement' => ModePaiement::Cheque->value]);

    $rappro = RapprochementBancaire::create([
        'association_id' => TenantContext::currentId(),
        'compte_id' => $compteBancaire->id, 'date_fin' => '2026-05-31',
        'solde_ouverture' => 0.0, 'solde_fin' => 80.0,
        'statut' => StatutRapprochement::EnCours->value, 'saisi_par' => userIdJrn(),
    ]);
    $service = app(RapprochementBancaireService::class);

    // --- POINTAGE ---
    $service->toggleTransaction($rappro->fresh(), 'recette', (int) $t1->id);

    // Une RemiseBancaire auto_generee=true doit exister
    expect(RemiseBancaire::where('auto_generee', true)->count())->toBe(1);
    $remise = RemiseBancaire::where('auto_generee', true)->first();
    expect($remise)->not->toBeNull();
    expect($remise->numero)->not->toBeNull(); // auto-remise numérotée (séquence manuelle)
    expect($remise->comptabilisee_at)->not->toBeNull();

    // Le T4 doit exister : journal=banque, remise_id = la remise auto, rapprochement_id = le rappro
    $t4 = Transaction::where('journal', JournalComptable::Banque->value)
        ->where('remise_id', $remise->id)
        ->where('rapprochement_id', $rappro->id)
        ->whereHas('lignes', fn ($q) => $q->where('debit', '>', 0)->whereHas('compte', fn ($c) => $c->bancaires()))
        ->first();
    expect($t4)->not->toBeNull();

    // Le chèque source N'a PAS remise_id (Approche X : reste standalone)
    expect($t1->fresh()->remise_id)->toBeNull();

    // Solde = 80
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(80.0);

    // T2 encaissement existe
    $t2 = app(ReglementOperationService::class)->trouverEncaissementT2($t1->fresh());
    expect($t2)->not->toBeNull();

    // --- DÉPOINTAGE ---
    $service->toggleTransaction($rappro->fresh(), 'recette', (int) $t1->id);

    // La remise auto et son T4 ont disparu
    expect(RemiseBancaire::where('auto_generee', true)->count())->toBe(0);
    expect(Transaction::where('journal', JournalComptable::Banque->value)
        ->whereHas('lignes', fn ($q) => $q->where('debit', '>', 0)->whereHas('compte', fn ($c) => $c->bancaires()))
        ->count())->toBe(0);

    // Solde = 0
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(0.0);

    // T2 encaissement subsiste
    $t2After = app(ReglementOperationService::class)->trouverEncaissementT2($t1->fresh());
    expect($t2After)->not->toBeNull();
});

it('pointer un chèque COMPTANT loose (lumpé) crée une remise auto_generee avec T4, dépointer supprime la remise et déletre 5112', function () {
    config(['compta.use_partie_double' => true]);
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $tiers = tiersJrn();

    $ligne5112 = creerLigne5112SourceJrn($tiers, 123.00, $compte512); // comptant lumpé
    $cheque = Transaction::find($ligne5112->transaction_id);
    $cheque->update(['compte_id' => $compteBancaire->id]);

    // Garde-fou : cas lumpé (pas de T2 séparée)
    expect(app(ReglementOperationService::class)->trouverEncaissementT2($cheque->fresh()))->toBeNull();

    $rappro = RapprochementBancaire::create([
        'association_id' => TenantContext::currentId(),
        'compte_id' => $compteBancaire->id, 'date_fin' => '2026-05-31',
        'solde_ouverture' => 0.0, 'solde_fin' => 123.0,
        'statut' => StatutRapprochement::EnCours->value, 'saisi_par' => userIdJrn(),
    ]);
    $service = app(RapprochementBancaireService::class);

    // --- POINTAGE ---
    $service->toggleTransaction($rappro->fresh(), 'recette', (int) $cheque->id);

    // Une RemiseBancaire auto_generee=true
    expect(RemiseBancaire::where('auto_generee', true)->count())->toBe(1);
    $remise = RemiseBancaire::where('auto_generee', true)->first();
    expect($remise->numero)->not->toBeNull(); // auto-remise numérotée (séquence manuelle)
    expect($remise->comptabilisee_at)->not->toBeNull();

    // T4 existe avec rapprochement_id posé
    $t4 = Transaction::where('journal', JournalComptable::Banque->value)
        ->where('remise_id', $remise->id)
        ->where('rapprochement_id', $rappro->id)
        ->whereHas('lignes', fn ($q) => $q->where('debit', '>', 0)->whereHas('compte', fn ($c) => $c->bancaires()))
        ->first();
    expect($t4)->not->toBeNull();

    // Le chèque source n'a pas remise_id
    expect($cheque->fresh()->remise_id)->toBeNull();

    // Solde = 123
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(123.0);

    // --- DÉPOINTAGE ---
    $service->toggleTransaction($rappro->fresh(), 'recette', (int) $cheque->id);

    // Remise auto et T4 disparus
    expect(RemiseBancaire::where('auto_generee', true)->count())->toBe(0);
    expect(Transaction::where('journal', JournalComptable::Banque->value)
        ->whereHas('lignes', fn ($q) => $q->where('debit', '>', 0)->whereHas('compte', fn ($c) => $c->bancaires()))
        ->count())->toBe(0);

    // Solde = 0
    expect($service->calculerSoldePointage($rappro->fresh()))->toBe(0.0);

    // La ligne 5112 du chèque lumpé redevient un-lettrée
    expect($ligne5112->fresh()->lettrage_code)->toBeNull();
});
