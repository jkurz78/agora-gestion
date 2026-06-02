<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Livewire\RapprochementList;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RapprochementBancaireService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/EcritureGeneratorJournalTest.php';

beforeEach(function () {
    SystemeSeeder::seed();
    config(['compta.use_partie_double' => true]);
});

// ---------------------------------------------------------------------------
// Bug C — Total crédit liste rapprochement multiplié (inflated)
//
// Après pointage d'un chèque comptant loose, plusieurs transactions reçoivent
// rapprochement_id : T1 (journal=vente) + T4 dépôt (journal=banque).
//
// render() calculait :
//   Transaction::where('rapprochement_id', ...)->where('type', Recette)->sum(...)
// → incluait T4 banque → résultat 2× (250 au lieu de 125).
//
// Fix : ajouter ->operationnel() pour n'inclure que T1 (journal=vente/achat).
// ---------------------------------------------------------------------------

it('[BugC] RapprochementList affiche le total credit exact (pas multiple) apres pointage cheque loose', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $tiers = tiersJrn();
    $ligne5112 = creerLigne5112SourceJrn($tiers, 125.00, $compte512);

    // Relier la transaction au compteBancaire (comme un chèque de séance)
    $cheque = Transaction::findOrFail($ligne5112->transaction_id);
    $cheque->update(['compte_id' => $compteBancaire->id]);

    $rappro = RapprochementBancaire::create([
        'association_id'  => TenantContext::currentId(),
        'compte_id'       => $compteBancaire->id,
        'date_fin'        => '2026-05-31',
        'solde_ouverture' => 0.0,
        'solde_fin'       => 125.0,
        'statut'          => StatutRapprochement::EnCours->value,
        'saisi_par'       => userIdJrn(),
    ]);

    // Pointer le chèque : génère un dépôt T4 (journal=banque) avec rapprochement_id
    app(RapprochementBancaireService::class)->toggleTransaction(
        $rappro->fresh(), 'recette', (int) $cheque->id
    );

    // Vérifier que SANS operationnel() la somme est gonflée
    // (plusieurs transactions Recette pointées — T1 + T4 dépôt)
    $sommeSansScope = (float) Transaction::where('rapprochement_id', $rappro->id)
        ->where('type', TypeTransaction::Recette)
        ->sum('montant_total');

    // Ce doit être 250 (T1 vente 125 + T4 banque 125) — la requête actuelle avant fix
    expect($sommeSansScope)->toBeGreaterThan(125.0,
        "La requête sans scope doit gonfler le total (attendu > 125, obtenu: {$sommeSansScope})"
    );

    // -----------------------------------------------------------------------
    // Test principal : le composant RapprochementList doit afficher 125 (pas 250)
    // -----------------------------------------------------------------------
    $component = Livewire::test(RapprochementList::class)
        ->set('compte_id', $compteBancaire->id);

    $totals = $component->viewData('rapprochementTotals');
    $creditCalcule = $totals[$rappro->id]['credit'] ?? null;

    // RED avant fix : credit = 250 (car requête sans ->operationnel())
    // GREEN après fix : credit = 125
    expect($creditCalcule)->toBe(125.0,
        "Le total crédit affiché doit être 125.0 (obtenu: {$creditCalcule})"
    );
});
