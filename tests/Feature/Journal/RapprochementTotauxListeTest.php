<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Livewire\RapprochementList;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RapprochementBancaireService;
use App\Services\RemiseBancaireService;
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
// Après pointage d'une remise manuelle, plusieurs transactions reçoivent
// rapprochement_id : T1 (journal=vente) + T4 dépôt (journal=banque).
//
// render() calculait :
//   Transaction::where('rapprochement_id', ...)->where('type', Recette)->sum(...)
// → incluait T4 banque → résultat 2× (250 au lieu de 125).
//
// Fix : ajouter ->operationnel() pour n'inclure que T1 (journal=vente/achat).
// ---------------------------------------------------------------------------

it('[BugC] RapprochementList affiche le total credit exact (pas multiple) apres pointage d une remise manuelle', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $tiers = tiersJrn();
    $ligne5112 = creerLigne5112SourceJrn($tiers, 125.00, $compte512);

    // Relier le chèque source au compteBancaire (comme un chèque de séance)
    $cheque = Transaction::findOrFail($ligne5112->transaction_id);
    $cheque->update(['compte_id' => $compteBancaire->id]);

    // Remise manuelle comptabilisée : crée la T4 (512X D / 5112 C, journal=banque)
    $remise = RemiseBancaire::create([
        'association_id' => TenantContext::currentId(),
        'numero' => 7001,
        'date' => '2026-05-26',
        'mode_paiement' => ModePaiement::Cheque,
        'compte_cible_id' => $compteBancaire->id,
        'libelle' => 'Remise BugC',
        'saisi_par' => userIdJrn(),
    ]);
    app(RemiseBancaireService::class)->comptabiliser($remise, [(int) $cheque->id]);

    $rappro = RapprochementBancaire::create([
        'association_id' => TenantContext::currentId(),
        'compte_id' => $compteBancaire->id,
        'date_fin' => '2026-05-31',
        'solde_ouverture' => 0.0,
        'solde_fin' => 125.0,
        'statut' => StatutRapprochement::EnCours->value,
        'saisi_par' => userIdJrn(),
    ]);

    // Pointer la remise : T1 (journal=vente) + T4 dépôt (journal=banque) reçoivent rapprochement_id
    app(RapprochementBancaireService::class)->toggleTransaction(
        $rappro->fresh(), 'remise', (int) $remise->id
    );

    // SANS operationnel() la somme est gonflée (T1 vente 125 + T4 banque 125 = 250)
    $sommeSansScope = (float) Transaction::where('rapprochement_id', $rappro->id)
        ->where('type', TypeTransaction::Recette)
        ->sum('montant_total');
    expect($sommeSansScope)->toBeGreaterThan(125.0,
        "La requête sans scope doit gonfler le total (attendu > 125, obtenu: {$sommeSansScope})"
    );

    // Le composant RapprochementList doit afficher 125 (operationnel() exclut la T4 banque)
    $component = Livewire::test(RapprochementList::class)
        ->set('compte_id', $compteBancaire->id);

    $totals = $component->viewData('rapprochementTotals');
    $creditCalcule = $totals[$rappro->id]['credit'] ?? null;

    expect($creditCalcule)->toBe(125.0,
        "Le total crédit affiché doit être 125.0 (obtenu: {$creditCalcule})"
    );
});
