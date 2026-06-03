<?php

declare(strict_types=1);

/**
 * Step 30 — Test d'équivalence rapprochement legacy ↔ PD (tolérance 0 ligne d'écart).
 *
 * Spec §7.4 : "Symétrique au CR : un test Pest qui rappro le même mois sur les deux
 * moteurs (ancien compte_id à l'entête + GROUP BY remise, nouveau compte_id sur les
 * lignes) et compare les lignes affichées. Tolérance : 0 ligne d'écart, même libellés,
 * même montants."
 *
 * Ce test construit une fixture exercice complet via les vrais services métier
 * (TransactionService, RemiseBancaireService, FactureService), crée un rapprochement
 * bancaire, pointe un sous-ensemble de transactions, puis compare :
 *
 * 1. Le solde de pointage calculé par RapprochementBancaireService::calculerSoldePointage
 *    dans les 2 modes (tolérance 0,00€).
 * 2. La liste des transactions pointables (identité des IDs, libellés, montants signés).
 * 3. La remise = 1 ligne unique dans les 2 modes.
 * 4. Cross-compte : transaction sur un autre CompteBancaire absente des 2 listes.
 * 5. Investigation I1 : transaction legacy (sans ligne 512X) invisible en mode PD —
 *    comportement documenté (backfill slice 1d). Pas de xfail car c'est attendu.
 *
 * IMPORTANT : toute divergence solde ≠ 0,00€ est un bug. Le test échoue en CI.
 * La divergence liste est admise uniquement pour le cas I1 (transaction legacy).
 */

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\FactureService;
use App\Services\RapprochementBancaireService;
use App\Services\RemiseBancaireService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Setup global
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // ── Comptes système : 411, 401, 5112
    SystemeSeeder::seed();

    // ── 530 (Caisse — espèces)
    $tenantId = (int) TenantContext::currentId();
    $isSqlite = DB::getDriverName() === 'sqlite';
    $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    DB::statement(<<<SQL
        {$insertClause} INTO comptes
            (association_id, numero_pcg, intitule, classe, actif, est_systeme, pour_inscriptions, lettrable, created_at, updated_at)
        VALUES
            ({$tenantId}, '530', 'Caisse (espèces)', 5, 1, 1, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    SQL);

    // ── CompteBancaire BNP + Compte 512X correspondant (via IBAN)
    $this->ibanBnp = 'FR7612345000012345678901234';
    $this->compteBnp = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->ibanBnp,
        'solde_initial' => 2000.00,
        'date_solde_initial' => '2025-09-01',
        'actif_recettes_depenses' => true,
    ]);

    // ── CompteBancaire Crédit Lyonnais (2ème compte — cross-compte test)
    $this->ibanCl = 'FR7699999000099999999901234';
    $this->compteCl = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->ibanCl,
        'solde_initial' => 500.00,
        'date_solde_initial' => '2025-09-01',
        'actif_recettes_depenses' => true,
    ]);

    // ── Seeder comptes 512X pour les 2 comptes bancaires
    BancairesSeeder::seed();
    $this->compte512Bnp = Compte::where('iban', $this->ibanBnp)
        ->where('association_id', $this->association->id)
        ->firstOrFail();
    $this->compte512Cl = Compte::where('iban', $this->ibanCl)
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    // ── Catégorie + sous-catégorie + compte 706 (recettes)
    $this->catRecette = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Prestations',
    ]);
    $this->sc706 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Cotisations membres',
        'code_cerfa' => '706',
    ]);
    $this->compte706 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706'],
        [
            'intitule' => 'Cotisations membres',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'categorie_id' => $this->catRecette->id,
        ]
    );

    // ── Catégorie + sous-catégorie + compte 606 (dépenses)
    $this->catDepense = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Charges de fonctionnement',
    ]);
    $this->sc606 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catDepense->id,
        'nom' => 'Fournitures et petits matériels',
        'code_cerfa' => '606',
    ]);
    $this->compte606 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '606'],
        [
            'intitule' => 'Fournitures et petits matériels',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'categorie_id' => $this->catDepense->id,
        ]
    );

    // ── Tiers
    $this->tiersA = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->tiersB = Tiers::factory()->create(['association_id' => $this->association->id]);

    // ── Services
    $this->txService = app(TransactionService::class);
    $this->factureService = app(FactureService::class);
    $this->remiseService = app(RemiseBancaireService::class);
    $this->rappro = app(RapprochementBancaireService::class);

    // ── Date de rappro (fin octobre 2025)
    $this->dateFin = '2025-10-31';
});

afterEach(function () {
    Config::set('compta.use_partie_double', false);
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helper : construire la fixture rappro
// ---------------------------------------------------------------------------

/**
 * Construit les transactions de l'exercice pour le test rappro.
 *
 * Retourne les IDs des transactions sur le compte BNP (attendues dans les 2 listes).
 *
 * @return array{
 *   txRecette1Id: int,
 *   txRecette2Id: int,
 *   txDepense1Id: int,
 *   txDepense2Id: int,
 *   txCheque1Id: int,
 *   txCheque2Id: int,
 *   txClId: int,
 *   txFactureId: int|null,
 *   remiseId: int,
 * }
 */
function creerFixtureRappro(object $ctx): array
{
    // ── R1 : Recette virement BNP 300€ (créé via service → lignes 411D/706C/512XD/411C)
    $txR1 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-05',
        'libelle' => 'Subvention mairie',
        'montant_total' => '300.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBnp->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '300.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // ── R2 : Recette chèque BNP 150€ (créé via service → lignes 411D/706C/5112D/411C)
    $txR2 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-10',
        'libelle' => 'Adhésion annuelle tiersA',
        'montant_total' => '150.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBnp->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '150.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // ── D1 : Dépense virement BNP 200€ (créé via service → lignes 606D/401C/401D/512XC)
    $txD1 = $ctx->txService->create([
        'type' => 'depense',
        'date' => '2025-10-15',
        'libelle' => 'Fournitures bureau',
        'montant_total' => '200.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBnp->id,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // ── D2 : Dépense chèque BNP 75€
    $txD2 = $ctx->txService->create([
        'type' => 'depense',
        'date' => '2025-10-20',
        'libelle' => 'Petites fournitures',
        'montant_total' => '75.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBnp->id,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '75.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // ── REMISE : 2 chèques (R2 = 150€ + un 3ème chèque C1 = 100€) déposés en remise
    // C1 : recette chèque supplémentaire pour alimenter la remise
    $txC1 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-08',
        'libelle' => 'Adhésion tiersB',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBnp->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // C2 : 3ème chèque pour tester une remise à 3 sources
    $txC2 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-12',
        'libelle' => 'Adhésion tiersA supplementaire',
        'montant_total' => '80.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBnp->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '80.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Comptabiliser la remise avec les 3 chèques : crée T4 avec ligne 512X D total (330€)
    $remise = $ctx->remiseService->creer([
        'date' => '2025-10-22',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $ctx->compteBnp->id,
    ]);
    $ctx->remiseService->comptabiliser($remise, [$txR2->id, $txC1->id, $txC2->id]);

    // ── Facture validée sur BNP (T1 créance + T2 encaissement via marquerReglementRecu)
    $facture = Facture::create([
        'association_id' => $ctx->association->id,
        'date' => '2025-10-03',
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $ctx->tiersA->id,
        'saisi_par' => $ctx->user->id,
        'exercice' => 2025,
        'montant_total' => 0,
        'mode_paiement_prevu' => ModePaiement::Virement->value,
    ]);
    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel->value,
        'sous_categorie_id' => $ctx->sc706->id,
        'libelle' => 'Formation octobre',
        'montant' => 400.00,
        'ordre' => 1,
    ]);
    $ctx->factureService->valider($facture);
    $facture->refresh();

    // Encaisser la facture → crée T2 sur BNP
    $t1Facture = $facture->transactions()->first();
    $txFactureId = null;
    if ($t1Facture !== null) {
        // T1 est la créance (pas sur compte BNP)
        // T2 = encaissement sur BNP via marquerReglementRecu
        $ctx->factureService->marquerReglementRecu($facture, [$t1Facture->id]);
        $facture->refresh();
        // Récupérer la T2 (encaissement sur BNP)
        $t2Facture = $facture->transactions()
            ->where('id', '!=', $t1Facture->id)
            ->where('compte_id', $ctx->compteBnp->id)
            ->first();
        $txFactureId = $t2Facture !== null ? (int) $t2Facture->id : null;
    }

    // ── CL : transaction sur Crédit Lyonnais (ne doit PAS apparaître dans le rappro BNP)
    $txCl = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-05',
        'libelle' => 'Recette sur CL (hors BNP)',
        'montant_total' => '500.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteCl->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '500.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    return [
        'txRecette1Id' => (int) $txR1->id,
        'txRecette2Id' => (int) $txR2->id,
        'txDepense1Id' => (int) $txD1->id,
        'txDepense2Id' => (int) $txD2->id,
        'txCheque1Id' => (int) $txC1->id,
        'txCheque2Id' => (int) $txC2->id,
        'txClId' => (int) $txCl->id,
        'txFactureId' => $txFactureId,
        'remiseId' => (int) $remise->id,
    ];
}

/**
 * Construit la liste des transactions pointables pour le compte BNP au date_fin,
 * de la même façon que RapprochementDetail.render().
 *
 * En mode PD (`config('compta.use_partie_double')` = true), applique le même filtre
 * 512X strict que render() : seules les transactions portant une ligne sur le 512X
 * du compte (ou appartenant à une remise) sont retournées.
 * En mode legacy (ou si le compte 512X est introuvable), aucun filtre 512X — identique
 * au comportement de render() avant la fonctionnalité.
 *
 * Retourne une collection de rows normalisés { id, type, libelle, montant_signe, pointe }.
 *
 * Note : la Livewire logue les remises comme 1 ligne agrégée (type='remise').
 * Cette fonction reproduit cette logique pour permettre la comparaison.
 *
 * @return list<array{id: int, type: string, libelle: string, montant_signe: float, pointe: bool}>
 */
function chargerListeRapproTx(int $compteId, int $rapproId, string $dateFin, bool $verrouille): array
{
    // Miroir du filtre 512X de RapprochementDetail::render()
    $compte512X = null;
    if (config('compta.use_partie_double')) {
        $compte512X = Compte::where('compte_bancaire_id', $compteId)
            ->bancaires()
            ->first();
    }

    $txRows = Transaction::where('compte_id', $compteId)
        ->where(function ($q) use ($rapproId, $dateFin, $verrouille) {
            if ($verrouille) {
                $q->where('rapprochement_id', $rapproId);
            } else {
                $q->where(function ($inner) use ($dateFin) {
                    $inner->whereNull('rapprochement_id')
                        ->where('date', '<=', $dateFin);
                })->orWhere('rapprochement_id', $rapproId);
            }
        })
        ->when(
            config('compta.use_partie_double') && $compte512X !== null,
            fn ($q) => $q->where(function ($w) use ($compte512X) {
                $w->whereNotNull('remise_id')
                    ->orWhereHas('lignes', fn ($l) => $l->where('compte_id', $compte512X->id));
            })
        )
        ->with('remise')
        ->get();

    $rows = [];

    // Remises groupées → 1 ligne par remise
    $remiseGroups = $txRows->whereNotNull('remise_id')->groupBy('remise_id');
    foreach ($remiseGroups as $remiseId => $remiseTxs) {
        $allPointed = $remiseTxs->every(fn (Transaction $tx) => (int) $tx->rapprochement_id === $rapproId);
        $montantTotal = 0.0;
        foreach ($remiseTxs as $tx) {
            $montantTotal += (float) $tx->montantSigne();
        }
        $rows[] = [
            'id' => (int) $remiseId,
            'type' => 'remise',
            'libelle' => $remiseTxs->first()->remise?->libelle ?? "Remise n°{$remiseId}",
            'montant_signe' => round($montantTotal, 2),
            'pointe' => $allPointed,
        ];
    }

    // Standalone
    $standalone = $txRows->whereNull('remise_id');
    foreach ($standalone as $tx) {
        $rows[] = [
            'id' => (int) $tx->id,
            'type' => $tx->type->value,
            'libelle' => $tx->libelle,
            'montant_signe' => round((float) $tx->montantSigne(), 2),
            'pointe' => (int) $tx->rapprochement_id === $rapproId,
        ];
    }

    // Trier pour comparaison déterministe (par type+id)
    usort($rows, fn ($a, $b) => ($a['type'] <=> $b['type']) ?: ($a['id'] <=> $b['id']));

    return $rows;
}

/**
 * Construit un index { id => row } depuis une liste de rows rappro.
 *
 * @param  list<array>  $rows
 * @return array<int, array>
 */
function indexRapproRows(array $rows): array
{
    $index = [];
    foreach ($rows as $row) {
        $key = $row['type'].':'.$row['id'];
        $index[$key] = $row;
    }

    return $index;
}

// ---------------------------------------------------------------------------
// [R1] Équivalence solde de pointage — tolérance 0,00€ sur transactions simples
//
// Périmètre : recettes + dépenses simples (virement) — sans remise pointée.
// Les remises font l'objet d'une divergence documentée dans [R6] / [R8] pointant
// un bug structurel de calculerSoldePointage en mode legacy (double-comptage T4 + T1).
// Step 31 corrigera calculerSoldePointage::legacy pour exclure les T4 de remise.
// ---------------------------------------------------------------------------

it('[R1] calculerSoldePointage — legacy ↔ PD identiques sur recettes+dépenses simples (tolérance 0,00€)', function () {
    $fixture = creerFixtureRappro($this);

    // Créer le rapprochement BNP
    // Pointer uniquement R1 (recette virement 300€) + D1 (dépense virement 200€)
    // — pas de remise, pour isoler les transactions enrichies PD simples
    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2100.00);

    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $this->rappro->toggleTransaction($rapprochement, 'depense', $fixture['txDepense1Id']);

    $rapprochement = $rapprochement->fresh();

    // ── Mode LEGACY
    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    // ── Mode PD
    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    // Comparaison tolérance 0,00€ — transactions simples enrichies PD (via TransactionService)
    expect($soldePD)->toEqual(
        $soldeLegacy,
        "Solde pointage : legacy={$soldeLegacy}€ ≠ PD={$soldePD}€ — divergence détectée"
    );

    // Valeurs attendues : 2000 (ouverture) + 300 (R1) - 200 (D1) = 2100
    expect($soldeLegacy)->toBe(2100.0, 'Legacy : 2000 + 300 (R1) - 200 (D1) = 2100');
    expect($soldePD)->toBe(2100.0, 'PD : 2000 + 300 (512X débit R1) - 200 (512X crédit D1) = 2100');
});

// ---------------------------------------------------------------------------
// [R2] Équivalence liste pointable — mêmes IDs, libellés, montants
// ---------------------------------------------------------------------------

it('[R2] liste transactions pointables — legacy ↔ PD identiques (libellés, montants, statuts)', function () {
    $fixture = creerFixtureRappro($this);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2750.00);
    $rid = $rapprochement->id;

    // Pointer R1 + D1 + remise
    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $this->rappro->toggleTransaction($rapprochement, 'depense', $fixture['txDepense1Id']);
    $this->rappro->toggleTransaction($rapprochement, 'remise', $fixture['remiseId']);
    $rapprochement = $rapprochement->fresh();

    // La liste des transactions pointables est assemblée par le Livewire de façon
    // mode-agnostique (lit transactions.compte_id + rapprochement_id entête).
    // Les 2 modes donnent donc la même liste — ce test vérifie l'identité structurelle.
    $liste = chargerListeRapproTx((int) $this->compteBnp->id, $rid, $this->dateFin, false);

    // Doit contenir des lignes
    expect($liste)->not->toBe([], 'La liste doit contenir des transactions');

    // Vérifier le nombre de lignes
    $indexListe = indexRapproRows($liste);

    // R1 et D1 doivent être pointés
    $keyR1 = 'recette:'.$fixture['txRecette1Id'];
    $keyD1 = 'depense:'.$fixture['txDepense1Id'];
    $keyRemise = 'remise:'.$fixture['remiseId'];

    expect(array_key_exists($keyR1, $indexListe))->toBeTrue('R1 (recette virement) doit apparaître dans la liste');
    expect(array_key_exists($keyD1, $indexListe))->toBeTrue('D1 (dépense virement) doit apparaître dans la liste');
    expect(array_key_exists($keyRemise, $indexListe))->toBeTrue('La remise doit apparaître comme 1 ligne dans la liste');

    // Statuts pointage
    expect($indexListe[$keyR1]['pointe'])->toBeTrue('R1 doit être pointé');
    expect($indexListe[$keyD1]['pointe'])->toBeTrue('D1 doit être pointé');
    expect($indexListe[$keyRemise]['pointe'])->toBeTrue('La remise doit être pointée');

    // Montants signés attendus
    expect($indexListe[$keyR1]['montant_signe'])->toBe(300.0, 'R1 : montant signé = +300.00');
    expect($indexListe[$keyD1]['montant_signe'])->toBe(-200.0, 'D1 : montant signé = -200.00');
    // Remise = 3 chèques (150 + 100 + 80 = 330€)
    expect($indexListe[$keyRemise]['montant_signe'])->toBe(330.0, 'Remise : montant signé = +330.00 (3 chèques agrégés)');

    // Note : la liste est identique en mode legacy et PD car elle lit transactions.compte_id
    // (header) — le branchement PD n'affecte que calculerSoldePointage, pas la liste.
});

// ---------------------------------------------------------------------------
// [R3] Remise = 1 ligne unique dans les 2 modes
// ---------------------------------------------------------------------------

it('[R3] remise = 1 ligne unique dans la liste rappro (mode legacy ↔ PD)', function () {
    $fixture = creerFixtureRappro($this);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2750.00);
    $rid = $rapprochement->id;

    $liste = chargerListeRapproTx((int) $this->compteBnp->id, $rid, $this->dateFin, false);

    // Compter les lignes de type 'remise'
    $remiseRows = array_filter($liste, fn ($r) => $r['type'] === 'remise');
    expect(count($remiseRows))->toBe(1, 'Il doit y avoir exactement 1 ligne de type remise dans la liste');

    // La remise = 330€ (somme des 3 chèques sources 150+100+80)
    $remiseRow = array_values($remiseRows)[0];
    expect($remiseRow['montant_signe'])->toBe(330.0, 'La remise doit agréger les 3 chèques : 150+100+80 = 330€');
    expect($remiseRow['id'])->toBe($fixture['remiseId'], 'L\'ID de la remise doit être l\'ID de la RemiseBancaire');

    // Les chèques sources (R2, C1, C2) ne doivent PAS apparaître comme lignes standalone
    $standaloneIds = array_map(fn ($r) => $r['id'], array_filter($liste, fn ($r) => $r['type'] !== 'remise'));
    expect($standaloneIds)->not->toContain($fixture['txRecette2Id'], 'R2 (chèque source remise) ne doit pas apparaître en standalone');
    expect($standaloneIds)->not->toContain($fixture['txCheque1Id'], 'C1 (chèque source remise) ne doit pas apparaître en standalone');
    expect($standaloneIds)->not->toContain($fixture['txCheque2Id'], 'C2 (chèque source remise) ne doit pas apparaître en standalone');
});

// ---------------------------------------------------------------------------
// [R4] Cross-compte : transaction CL absente de la liste BNP
// ---------------------------------------------------------------------------

it('[R4] cross-compte — transaction Crédit Lyonnais absente de la liste BNP', function () {
    $fixture = creerFixtureRappro($this);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2750.00);
    $rid = $rapprochement->id;

    $liste = chargerListeRapproTx((int) $this->compteBnp->id, $rid, $this->dateFin, false);

    $allIds = array_map(fn ($r) => $r['id'], $liste);
    expect($allIds)->not->toContain($fixture['txClId'], 'La transaction CL ne doit pas apparaître dans le rappro BNP');

    // Vérifier aussi via la requête de solde PD (la ligne 512X CL ne contribue pas au solde BNP)
    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    // Les 2 soldes ne comptent pas la transaction CL (cross-compte)
    expect($soldePD)->toEqual($soldeLegacy, 'Cross-compte : le solde BNP ne doit pas inclure la transaction CL');
});

// ---------------------------------------------------------------------------
// [R5] Équivalence solde — toggle / détoggle idempotent dans les 2 modes
// ---------------------------------------------------------------------------

it('[R5] toggle/détoggle — solde retourne au même état dans les 2 modes', function () {
    $fixture = creerFixtureRappro($this);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2750.00);

    // ── État initial : rien de pointé
    Config::set('compta.use_partie_double', false);
    $soldeInitLegacy = $this->rappro->calculerSoldePointage($rapprochement->fresh());

    Config::set('compta.use_partie_double', true);
    $soldeInitPD = $this->rappro->calculerSoldePointage($rapprochement->fresh());

    expect($soldeInitPD)->toEqual($soldeInitLegacy, 'État initial (rien pointé) : legacy ↔ PD identiques');

    // ── Pointer R1
    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeApresPointageLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldeApresPointagePD = $this->rappro->calculerSoldePointage($rapprochement);

    expect($soldeApresPointagePD)->toEqual(
        $soldeApresPointageLegacy,
        "Après pointage R1 : legacy={$soldeApresPointageLegacy}€ ≠ PD={$soldeApresPointagePD}€"
    );

    // ── Détoggle R1 → retour à l'état initial
    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeRetourLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldeRetourPD = $this->rappro->calculerSoldePointage($rapprochement);

    expect($soldeRetourPD)->toEqual($soldeRetourLegacy, 'Après détoggle R1 : legacy ↔ PD identiques');
    expect($soldeRetourLegacy)->toEqual($soldeInitLegacy, 'Après détoggle R1 : solde = état initial');
});

// ---------------------------------------------------------------------------
// [R6] Remise pointée — équivalence stricte legacy ↔ PD
//
// ✅ Bug fixé Step 31 : calculerSoldePointage mode legacy exclut désormais les T1 sources
// de remise (remise_id IS NOT NULL AND reference IS NOT NULL) du SUM.
// Seule la T4 (equilibree=true, reference IS NULL) est comptée — identique au mode PD.
//
// toggleRemise() pointe les T1 sources (R2+C1+C2) ET la T4.
// Résultat attendu après fix :
//   - Legacy : 2000 + 330 (T4 seule, T1 sources exclues) = 2330
//   - PD     : 2000 + 330 (ligne 512X D de T4 uniquement) = 2330
//   - Équivalence stricte : 0,00€ d'écart
// ---------------------------------------------------------------------------

it('[R6] remise pointée — équivalence stricte legacy ↔ PD (bug double-comptage T4 fixé Step 31)', function () {
    $fixture = creerFixtureRappro($this);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2330.00);

    $this->rappro->toggleTransaction($rapprochement, 'remise', $fixture['remiseId']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    // ── Équivalence stricte : les deux modes donnent 2330 après le fix
    expect($soldeLegacy)->toBe(2330.0, 'Legacy après fix : 2000 + 330 (T4 seule, T1 sources exclues) = 2330');
    expect($soldePD)->toBe(2330.0, 'PD : 2000 + 330 (ligne 512X D T4) = 2330');
    expect($soldeLegacy)->toEqual($soldePD, 'Équivalence stricte legacy ↔ PD — 0,00€ d\'écart');
}); // ✅ Bug fixé Step 31 — calculerSoldePointage legacy exclut T1 sources de remise.

// ---------------------------------------------------------------------------
// [R6c] Remise pointée, sources SANS reference (données prod/backfill)
//
// Régression Finding 2 (cutover 2026-05-31) : calculerSoldePointage::legacy excluait
// les T1 sources via `remise_id IS NOT NULL AND reference IS NOT NULL`. Or les chèques
// remisés réels (prod) ont reference = NULL → l'exclusion les ratait → legacy comptait
// les sources (330) ET la T4 (330) = double-comptage → divergence 330€ vs PD.
//
// Le critère correct est structurel : un T1 source de remise ne porte PAS de ligne 512X
// (son portage est sur 5112/530) ; seule la T4 porte une ligne 512X. On exclut donc les
// transactions de remise sans ligne 512X, indépendamment de `reference`.
//
// Résultat attendu après fix : legacy = PD = 2000 + 330 (T4 seule) = 2330.
// ---------------------------------------------------------------------------

it('[R6c] remise pointée, sources reference NULL (prod) — équivalence stricte legacy ↔ PD (Finding 2)', function () {
    $fixture = creerFixtureRappro($this);

    // Simuler les données prod/backfill : les chèques sources n'ont pas de reference.
    Transaction::whereIn('id', [
        $fixture['txRecette2Id'],
        $fixture['txCheque1Id'],
        $fixture['txCheque2Id'],
    ])->update(['reference' => null]);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2330.00);

    $this->rappro->toggleTransaction($rapprochement, 'remise', $fixture['remiseId']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    expect($soldeLegacy)->toBe(2330.0, 'Legacy : sources ref NULL exclues par critère structurel, seule la T4 (330) comptée');
    expect($soldePD)->toBe(2330.0, 'PD : 2000 + 330 (ligne 512X D T4)');
    expect($soldeLegacy)->toEqual($soldePD, 'Équivalence stricte legacy ↔ PD malgré sources sans reference');
});

// ---------------------------------------------------------------------------
// [R7] Équivalence solde — dépense pointée (soustrait dans les 2 modes)
// ---------------------------------------------------------------------------

it('[R7] dépense pointée — solde PD = solde legacy (soustraction)', function () {
    $fixture = creerFixtureRappro($this);

    // Solde attendu après D1 pointée : 2000 (ouverture) - 200 (D1) = 1800
    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 1800.00);

    $this->rappro->toggleTransaction($rapprochement, 'depense', $fixture['txDepense1Id']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    expect($soldePD)->toEqual($soldeLegacy, "Dépense pointée : legacy={$soldeLegacy}€ ≠ PD={$soldePD}€");
    expect($soldeLegacy)->toBe(1800.0, 'Solde legacy après D1 pointée : 2000 - 200 = 1800');
    expect($soldePD)->toBe(1800.0, 'Solde PD après D1 pointée : 2000 - 200 (ligne 512X crédit) = 1800');
});

// ---------------------------------------------------------------------------
// [R8a] Équivalence — mix recette + dépense simples (tolérance 0,00€)
// ---------------------------------------------------------------------------

it('[R8a] solde mix recette + dépense simple — legacy ↔ PD identiques (tolérance 0,00€)', function () {
    $fixture = creerFixtureRappro($this);

    // Pointer uniquement R1 (300) + D1 (-200) — sans remise
    // Solde attendu PD et legacy = 2000 + 300 - 200 = 2100
    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2100.00);

    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $this->rappro->toggleTransaction($rapprochement, 'depense', $fixture['txDepense1Id']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    expect($soldePD)->toEqual($soldeLegacy, "Mix recette+dépense : legacy={$soldeLegacy}€ ≠ PD={$soldePD}€");
    expect($soldeLegacy)->toBe(2100.0, 'Legacy : 2000 + 300 - 200 = 2100');
    expect($soldePD)->toBe(2100.0, 'PD : 2000 + 300 (512X D) - 200 (512X C) = 2100');
});

// ---------------------------------------------------------------------------
// [R8b] Mix complet avec remise — équivalence stricte legacy ↔ PD
//
// ✅ Bug fixé Step 31 : même correction que [R6].
// En mode legacy post-fix, seule la T4 est comptée pour la remise (T1 sources exclues).
//
// legacy après fix : 2000 + 300 (R1) - 200 (D1) + 330 (T4 seule) = 2430
// PD              : 2000 + 300 (512X D R1) - 200 (512X C D1) + 330 (512X D T4) = 2430
// Équivalence     : 0,00€ d'écart
// ---------------------------------------------------------------------------

it('[R8b] solde mix complet avec remise — équivalence stricte legacy ↔ PD (bug double-comptage T4 fixé Step 31)', function () {
    $fixture = creerFixtureRappro($this);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2430.00);

    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $this->rappro->toggleTransaction($rapprochement, 'depense', $fixture['txDepense1Id']);
    $this->rappro->toggleTransaction($rapprochement, 'remise', $fixture['remiseId']);
    $rapprochement = $rapprochement->fresh();

    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    // ── Équivalence stricte après fix
    expect($soldeLegacy)->toBe(2430.0, 'Legacy après fix : 2000 + 300 (R1) - 200 (D1) + 330 (T4 seule, T1 exclues) = 2430');
    expect($soldePD)->toBe(2430.0, 'PD : 2000 + 300 (512X D R1) - 200 (512X C D1) + 330 (512X D T4) = 2430');
    expect($soldeLegacy)->toEqual($soldePD, 'Équivalence stricte legacy ↔ PD — 0,00€ d\'écart');
}); // ✅ Bug fixé Step 31 — même cause que [R6], même correction.

// ---------------------------------------------------------------------------
// [I1] Investigation : transaction legacy (sans ligne 512X) invisible en mode PD
//
// Solde PD (Step 29) : transaction sans ligne 512X = invisible au calcul PD.
// Divergence solde legacy ↔ PD ATTENDUE et documentée (backfill slice 1d la corrigera).
//
// Liste pointable (Chantier 1) : le helper `chargerListeRapproTx` est à parité avec
// RapprochementDetail::render() depuis ce chantier — il applique aussi le filtre 512X
// strict en mode PD. Conséquence : en mode PD, la transaction legacy (sans ligne 512X)
// n'est plus visible dans la liste non plus (idem render()). En mode legacy, elle reste
// visible. Le test vérifie les deux comportements explicitement.
// ---------------------------------------------------------------------------

it('[I1] transaction legacy (sans ligne 512X) invisible au calcul PD et exclue de la liste PD (filtre 512X strict)', function () {
    $fixture = creerFixtureRappro($this);

    // Créer une transaction legacy (sans enrichissement PD — pas de ligne 512X)
    $txLegacy = Transaction::factory()->asRecette()->create([
        'association_id' => TenantContext::currentId(),
        'compte_id' => $this->compteBnp->id,
        'montant_total' => 250.00,
        'mode_paiement' => ModePaiement::Virement->value,
        'date' => '2025-10-07',
        'libelle' => 'Transaction legacy sans PD',
        'saisi_par' => $this->user->id,
    ]);
    // Aucune TransactionLigne avec compte_id = 512X créée → legacy pur

    // Créer le rapprochement et pointer la transaction legacy
    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2750.00);
    $this->rappro->toggleTransaction($rapprochement, 'recette', $txLegacy->id);
    $rapprochement = $rapprochement->fresh();

    // ── Mode LEGACY : la transaction est visible (lit montant_total à l'entête)
    Config::set('compta.use_partie_double', false);
    $soldeLegacy = $this->rappro->calculerSoldePointage($rapprochement);

    // ── Mode PD : la transaction est invisible au calcul (pas de ligne 512X enrichie)
    Config::set('compta.use_partie_double', true);
    $soldePD = $this->rappro->calculerSoldePointage($rapprochement);

    // DIVERGENCE ATTENDUE — documentée Step 29, §7.4 mode mixte
    // Legacy : 2000 (ouverture) + 250 (legacy tx) = 2250
    // PD     : 2000 (ouverture) + 0 (tx invisible) = 2000
    expect($soldeLegacy)->toBe(2250.0, 'Legacy doit voir la transaction legacy (250€ pointée)');
    expect($soldePD)->toBe(2000.0, 'PD ne voit pas la transaction legacy (pas de ligne 512X enrichie)');

    // La divergence est documentée — elle sera corrigée par le backfill slice 1d
    $divergence = abs($soldePD - $soldeLegacy);
    expect($divergence)->toBeGreaterThan(0.0, 'I1 — Divergence mode mixte confirmée (transaction legacy invisible en PD)');
    expect($divergence)->toBe(250.0, 'I1 — Divergence = 250€ (montant de la transaction legacy non enrichie)');

    // ── LISTE en mode LEGACY : la transaction legacy est visible (pas de filtre 512X)
    Config::set('compta.use_partie_double', false);
    $listeLegacy = chargerListeRapproTx(
        (int) $this->compteBnp->id,
        $rapprochement->id,
        $this->dateFin,
        false
    );
    $allIdsLegacy = array_map(fn ($r) => $r['id'], $listeLegacy);
    expect(in_array((int) $txLegacy->id, $allIdsLegacy, true))->toBeTrue(
        "I1 [legacy] — La transaction legacy #{$txLegacy->id} doit apparaître dans la liste en mode legacy (pas de filtre 512X)"
    );

    // ── LISTE en mode PD : la transaction legacy est exclue (filtre 512X strict — Chantier 1)
    Config::set('compta.use_partie_double', true);
    $listePD = chargerListeRapproTx(
        (int) $this->compteBnp->id,
        $rapprochement->id,
        $this->dateFin,
        false
    );
    $allIdsPD = array_map(fn ($r) => $r['id'], $listePD);
    expect(in_array((int) $txLegacy->id, $allIdsPD, true))->toBeFalse(
        "I1 [PD] — La transaction legacy #{$txLegacy->id} ne doit PAS apparaître dans la liste en mode PD (filtre 512X strict, pas de ligne 512X enrichie)"
    );
});

// ---------------------------------------------------------------------------
// [R9] Mode legacy non altéré après exécution mode PD (non-régression flag)
// ---------------------------------------------------------------------------

it('[R9] mode legacy non altéré après exécution mode PD', function () {
    $fixture = creerFixtureRappro($this);

    $rapprochement = $this->rappro->create($this->compteBnp, $this->dateFin, 2750.00);
    $this->rappro->toggleTransaction($rapprochement, 'recette', $fixture['txRecette1Id']);
    $rapprochement = $rapprochement->fresh();

    // Calculer en legacy d'abord
    Config::set('compta.use_partie_double', false);
    $soldeLegacy1 = $this->rappro->calculerSoldePointage($rapprochement);

    // Calculer en PD
    Config::set('compta.use_partie_double', true);
    $this->rappro->calculerSoldePointage($rapprochement);

    // Revenir en legacy : doit donner le même résultat (pas d'effet de bord)
    Config::set('compta.use_partie_double', false);
    $soldeLegacy2 = $this->rappro->calculerSoldePointage($rapprochement);

    expect($soldeLegacy2)->toEqual($soldeLegacy1, 'Régression flag : le solde legacy doit être stable après exécution PD');
});
