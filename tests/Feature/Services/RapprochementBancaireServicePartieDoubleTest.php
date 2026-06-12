<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RapprochementBancaireService;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Setup partagé : tenant + comptes système + compte bancaire 512X
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Activer le mode partie double
    Config::set('compta.use_partie_double', true);

    // Comptes système : 411, 401, 5112
    SystemeSeeder::seed();

    // 530 (Caisse — espèces)
    $tenantId = (int) TenantContext::currentId();
    $isSqlite = DB::getDriverName() === 'sqlite';
    $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    DB::statement(<<<SQL
        {$insertClause} INTO comptes
            (association_id, numero_pcg, intitule, classe, actif, est_systeme, pour_inscriptions, lettrable, created_at, updated_at)
        VALUES
            ({$tenantId}, '530', 'Caisse (espèces)', 5, 1, 1, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    SQL);

    // CompteBancaire + Compte 512X correspondant (via IBAN)
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
    BancairesSeeder::seed();
    $this->compte512X = Compte::where('iban', $this->iban)
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    // Compte 706 (classe 7) pour les ventilations
    $categorie = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Prestations',
    ]);
    $this->sc706 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
        'nom' => 'Cotisations',
        'code_cerfa' => '706',
    ]);
    $this->compte706 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706'],
        [
            'intitule' => 'Cotisations et adhésions',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // Compte 601 (classe 6) pour les dépenses
    $categorieDep = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Charges',
    ]);
    $this->sc601 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieDep->id,
        'nom' => 'Fournitures',
        'code_cerfa' => '601',
    ]);
    $this->compte601 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '601'],
        [
            'intitule' => 'Fournitures',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->service = app(RapprochementBancaireService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helpers locaux : créer des transactions enrichies PD
// ---------------------------------------------------------------------------

/**
 * Crée une transaction recette virement enrichie PD : 4 lignes (411D/706C/512XD/411C).
 *
 * Note : transactions.compte_id est une FK vers comptes_bancaires.
 * On passe le CompteBancaire pour l'entête, et le Compte 512X pour les lignes PD.
 */
function pdRecette(
    CompteBancaire $compteBancaire,
    Compte $compte512X,
    float $montant,
    Compte $compte706,
    Compte $compte411,
    Tiers $tiers,
    StatutReglement $statut = StatutReglement::EnAttente,
): Transaction {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Virement,
        'montant_total' => $montant,
        'compte_id' => $compteBancaire->id,
        'statut_reglement' => $statut->value,
        'equilibree' => true,
        'type_ecriture' => 'normale',
    ]);

    // 411 D tiers
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte411->id,
        'debit' => $montant,
        'credit' => 0,
        'tiers_id' => $tiers->id,
        'libelle' => 'Recette virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);
    // 706 C
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte706->id,
        'debit' => 0,
        'credit' => $montant,
        'tiers_id' => null,
        'libelle' => 'Recette virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);
    // 512X D (mouvement de trésorerie)
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte512X->id,
        'debit' => $montant,
        'credit' => 0,
        'tiers_id' => null,
        'libelle' => 'Recette virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);
    // 411 C tiers (contrepassation)
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte411->id,
        'debit' => 0,
        'credit' => $montant,
        'tiers_id' => $tiers->id,
        'libelle' => 'Recette virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    return $tx;
}

/**
 * Crée une transaction dépense virement enrichie PD : 4 lignes (601D/401C/401D/512XC).
 */
function pdDepense(
    CompteBancaire $compteBancaire,
    Compte $compte512X,
    float $montant,
    Compte $compte601,
    Compte $compte401,
    Tiers $tiers,
    StatutReglement $statut = StatutReglement::EnAttente,
): Transaction {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Depense,
        'mode_paiement' => ModePaiement::Virement,
        'montant_total' => $montant,
        'compte_id' => $compteBancaire->id,
        'statut_reglement' => $statut->value,
        'equilibree' => true,
        'type_ecriture' => 'normale',
    ]);

    // 601 D
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte601->id,
        'debit' => $montant,
        'credit' => 0,
        'tiers_id' => null,
        'libelle' => 'Dépense virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);
    // 401 C tiers
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte401->id,
        'debit' => 0,
        'credit' => $montant,
        'tiers_id' => $tiers->id,
        'libelle' => 'Dépense virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);
    // 401 D tiers (soldage immédiat)
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte401->id,
        'debit' => $montant,
        'credit' => 0,
        'tiers_id' => $tiers->id,
        'libelle' => 'Dépense virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);
    // 512X C (décaissement)
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte512X->id,
        'debit' => 0,
        'credit' => $montant,
        'tiers_id' => null,
        'libelle' => 'Dépense virement PD',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    return $tx;
}

// ===========================================================================
// A — calculerSoldePointage en mode PD
// ===========================================================================

test('[PD-A1] calculerSoldePointage retourne solde_ouverture si aucune transaction pointée', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1200.00);

    // Transaction PD existante mais non pointée
    $compte411 = compteSysteme('411');
    pdRecette($this->compteBancaire, $this->compte512X, 300.00, $this->compte706, $compte411, $this->tiers);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // Solde = solde_ouverture (1000) car aucune transaction pointée
    expect($solde)->toBe(1000.00);
});

test('[PD-A2] calculerSoldePointage additionne les débits 512X pointés (recette virement)', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1300.00);

    $compte411 = compteSysteme('411');
    $tx = pdRecette($this->compteBancaire, $this->compte512X, 300.00, $this->compte706, $compte411, $this->tiers);
    $tx->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // 1000 (ouverture) + 300 (512X débit) = 1300
    expect($solde)->toBe(1300.00);
});

test('[PD-A3] calculerSoldePointage soustrait les crédits 512X pointés (dépense virement)', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 800.00);

    $compte401 = compteSysteme('401');
    $tx = pdDepense($this->compteBancaire, $this->compte512X, 200.00, $this->compte601, $compte401, $this->tiers);
    $tx->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // 1000 (ouverture) - 200 (512X crédit) = 800
    expect($solde)->toBe(800.00);
});

test('[PD-A4] calculerSoldePointage combine recettes et dépenses pointées', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1100.00);

    $compte411 = compteSysteme('411');
    $compte401 = compteSysteme('401');

    $recette = pdRecette($this->compteBancaire, $this->compte512X, 500.00, $this->compte706, $compte411, $this->tiers);
    $depense = pdDepense($this->compteBancaire, $this->compte512X, 400.00, $this->compte601, $compte401, $this->tiers);

    $recette->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);
    $depense->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // 1000 + 500 - 400 = 1100
    expect($solde)->toBe(1100.00);
});

// ===========================================================================
// B — Cross-compte : lignes 512X d'un autre compte ne polluent pas
// ===========================================================================

test('[PD-B] calculerSoldePointage ignore les lignes 512X d\'un autre compte bancaire', function () {
    // Créer un deuxième compte bancaire avec son propre 512X
    $ibanBnp = 'FR7699999000099999999901234';
    $compteBancaireBnp = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $ibanBnp,
    ]);
    BancairesSeeder::seed();
    $compte512Bnp = Compte::where('iban', $ibanBnp)
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1100.00);

    $compte411 = compteSysteme('411');

    // Transaction sur compte BNP, pointée sur ce rapprochement
    $txBnp = pdRecette($compteBancaireBnp, $compte512Bnp, 999.00, $this->compte706, $compte411, $this->tiers);
    $txBnp->update(['rapprochement_id' => $rapprochement->id]);

    // Transaction normale sur le compte CL
    $txCl = pdRecette($this->compteBancaire, $this->compte512X, 100.00, $this->compte706, $compte411, $this->tiers);
    $txCl->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // Seule la ligne 512X du compte CL est prise : 1000 + 100 = 1100
    // Les lignes BNP (compte 512X différent) sont ignorées
    expect($solde)->toBe(1100.00);
});

// ===========================================================================
// C — toggleTransaction en mode PD
// ===========================================================================

test('[PD-C1] toggleTransaction pointe une recette PD (rapprochement_id défini)', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1300.00);

    $compte411 = compteSysteme('411');
    $tx = pdRecette($this->compteBancaire, $this->compte512X, 300.00, $this->compte706, $compte411, $this->tiers);

    $this->service->toggleTransaction($rapprochement, 'recette', $tx->id);

    $txFresh = $tx->fresh();
    expect((int) $txFresh->rapprochement_id)->toBe($rapprochement->id)
        ->and($txFresh->statut_reglement)->toBe(StatutReglement::Pointe);
});

test('[PD-C2] toggleTransaction dépointe une recette PD déjà pointée', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1300.00);

    $compte411 = compteSysteme('411');
    $tx = pdRecette($this->compteBancaire, $this->compte512X, 300.00, $this->compte706, $compte411, $this->tiers);
    $tx->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);

    $this->service->toggleTransaction($rapprochement, 'recette', $tx->id);

    $txFresh = $tx->fresh();
    expect($txFresh->rapprochement_id)->toBeNull()
        ->and($txFresh->statut_reglement)->toBe(StatutReglement::EnAttente);
});

test('[PD-C3] toggleTransaction pointe une dépense PD', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 800.00);

    $compte401 = compteSysteme('401');
    $tx = pdDepense($this->compteBancaire, $this->compte512X, 200.00, $this->compte601, $compte401, $this->tiers);

    $this->service->toggleTransaction($rapprochement, 'depense', $tx->id);

    $txFresh = $tx->fresh();
    expect((int) $txFresh->rapprochement_id)->toBe($rapprochement->id)
        // Task 5 — reglerOuEncaisser() est maintenant appelé pour les dépenses aussi.
        // Le helper pdDepense crée 401C non lettrée avec tiers → reglerOuEncaisser génère T2,
        // lettre le 401, puis le resolver détecte 401 lettré + rapprochement_id → Pointe.
        ->and($txFresh->statut_reglement)->toBe(StatutReglement::Pointe);
});

// ===========================================================================
// D — Remise = 1 transaction T4 unique au rappro (pas de GROUP BY)
// ===========================================================================

test('[PD-D] remise comptabilisée via RemiseBancaireService = 1 ligne 512X au rappro', function () {
    // Créer 3 transactions chèque enrichies PD (avec ligne 5112)
    $compte5112 = compteSysteme('5112');
    $compte411 = compteSysteme('411');

    $montants = [100.00, 150.00, 200.00]; // total = 450.00
    $txIds = [];

    foreach ($montants as $montant) {
        $tx = Transaction::factory()->create([
            'association_id' => TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => $montant,
            'compte_id' => $this->compteBancaire->id,
            'statut_reglement' => StatutReglement::EnAttente->value,
            'equilibree' => true,
            'type_ecriture' => 'normale',
        ]);

        // 411 D / 706 C / 5112 D / 411 C
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $compte411->id,
            'debit' => $montant, 'credit' => 0, 'tiers_id' => $this->tiers->id,
            'libelle' => 'Recette chèque', 'montant' => 0, 'sous_categorie_id' => null,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $this->compte706->id,
            'debit' => 0, 'credit' => $montant, 'tiers_id' => null,
            'libelle' => 'Recette chèque', 'montant' => 0, 'sous_categorie_id' => null,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $compte5112->id,
            'debit' => $montant, 'credit' => 0, 'tiers_id' => null,
            'libelle' => 'Recette chèque', 'montant' => 0, 'sous_categorie_id' => null,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $compte411->id,
            'debit' => 0, 'credit' => $montant, 'tiers_id' => $this->tiers->id,
            'libelle' => 'Recette chèque', 'montant' => 0, 'sous_categorie_id' => null,
        ]);

        $txIds[] = $tx->id;
    }

    // Comptabiliser la remise via RemiseBancaireService (génère la T4)
    $remiseService = app(RemiseBancaireService::class);
    $remise = $remiseService->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
    ]);

    $remiseService->comptabiliser($remise, $txIds);

    // Récupérer la T4 créée
    $t4 = Transaction::where('remise_id', $remise->id)
        ->where('equilibree', true)
        ->whereNull('reference')
        ->first();

    expect($t4)->not->toBeNull('La T4 de remise doit exister après comptabiliser()');

    // La T4 doit avoir EXACTEMENT 1 ligne sur le compte 512X (débit total)
    $lignes512X = TransactionLigne::where('transaction_id', $t4->id)
        ->where('compte_id', $this->compte512X->id)
        ->get();

    expect($lignes512X)->toHaveCount(1, 'La T4 doit avoir 1 seule ligne 512X — pas de GROUP BY')
        ->and((float) $lignes512X->first()->debit)->toBe(450.00, 'La ligne 512X doit totaliser 450.00 (100+150+200)');
});

// ===========================================================================
// E — Transactions legacy sans lignes 512X : invisibles en mode PD
// ===========================================================================

test('[PD-E] transactions legacy (sans lignes 512X) sont invisibles au calcul PD du solde', function () {
    // En mode mixte, les transactions non enrichies (pas de lignes 512X) ne contribuent pas.
    // Ce comportement est documenté (Step 29) : mode mixte legacy/PD.
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1000.00);

    // Transaction legacy : rapprochement_id défini MAIS sans lignes 512X
    $txLegacy = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 500.00,
        'rapprochement_id' => $rapprochement->id,
        'statut_reglement' => StatutReglement::Pointe->value,
    ]);
    // Aucune TransactionLigne avec compte_id = 512X pour cette transaction

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // En mode PD : transaction legacy invisible → solde = ouverture seule (1000)
    expect($solde)->toBe(1000.00);
});

// ===========================================================================
// F — Verrouillage avec solde PD équilibré
// ===========================================================================

test('[PD-F] verrouiller réussit quand solde PD = solde_fin (écart = 0)', function () {
    // solde_ouverture = 1000 (solde_initial du compteBancaire), solde_fin = 1300
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1300.00);

    $compte411 = compteSysteme('411');
    $tx = pdRecette($this->compteBancaire, $this->compte512X, 300.00, $this->compte706, $compte411, $this->tiers);
    $tx->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);

    // Solde PD = 1000 + 300 = 1300 = solde_fin → écart = 0
    $this->service->verrouiller($rapprochement->fresh());

    expect($rapprochement->fresh()->statut)->toBe(StatutRapprochement::Verrouille);
});

test('[PD-F2] verrouiller échoue si solde PD ≠ solde_fin', function () {
    // solde_ouverture = 1000, solde_fin = 1500 (écart de 200)
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1500.00);

    $compte411 = compteSysteme('411');
    $tx = pdRecette($this->compteBancaire, $this->compte512X, 300.00, $this->compte706, $compte411, $this->tiers);
    $tx->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);

    // Solde PD = 1000 + 300 = 1300 ≠ 1500 → écart = -200 → exception
    expect(fn () => $this->service->verrouiller($rapprochement->fresh()))
        ->toThrow(RuntimeException::class);
});

// ===========================================================================
// G — Toggle remise en mode PD : seule la T4 contribue au solde 512X
// ===========================================================================

test('[PD-G] toggle remise pointée en mode PD : solde = ouverture + total remise (T4 seule, pas les T1 sources)', function () {
    // Créer 3 transactions chèque avec lignes 5112 (portage) — source T1a, T1b, T1c
    $compte5112 = compteSysteme('5112');
    $compte411 = compteSysteme('411');
    $montants = [100.00, 150.00, 200.00]; // total = 450.00
    $txIds = [];

    foreach ($montants as $montant) {
        $tx = Transaction::factory()->create([
            'association_id' => TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'mode_paiement' => ModePaiement::Cheque,
            'montant_total' => $montant,
            'compte_id' => $this->compteBancaire->id,
            'statut_reglement' => StatutReglement::EnAttente->value,
            'equilibree' => true,
            'type_ecriture' => 'normale',
        ]);

        // Lignes PD : 411D / 706C / 5112D / 411C (pas de ligne 512X sur les sources chèque)
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $compte411->id,
            'debit' => $montant, 'credit' => 0, 'tiers_id' => $this->tiers->id,
            'libelle' => 'Chèque source', 'montant' => 0, 'sous_categorie_id' => null,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $this->compte706->id,
            'debit' => 0, 'credit' => $montant, 'tiers_id' => null,
            'libelle' => 'Chèque source', 'montant' => 0, 'sous_categorie_id' => null,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $compte5112->id,
            'debit' => $montant, 'credit' => 0, 'tiers_id' => null,
            'libelle' => 'Chèque source', 'montant' => 0, 'sous_categorie_id' => null,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $compte411->id,
            'debit' => 0, 'credit' => $montant, 'tiers_id' => $this->tiers->id,
            'libelle' => 'Chèque source', 'montant' => 0, 'sous_categorie_id' => null,
        ]);

        $txIds[] = $tx->id;
    }

    // Comptabiliser la remise → crée la T4 (512X D 450.00, 5112 C 450.00)
    $remiseService = app(RemiseBancaireService::class);
    $remise = $remiseService->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
    ]);
    $remiseService->comptabiliser($remise, $txIds);

    // Créer le rapprochement (solde_fin = 1450 = ouverture 1000 + remise 450)
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1450.00);

    // Pointer la remise via toggleTransaction (pointe T1 sources + T4)
    $this->service->toggleTransaction($rapprochement, 'remise', $remise->id);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // Seule la T4 a une ligne 512X D (450.00). Les T1 sources ont 5112, pas 512X.
    // Solde attendu = 1000 (ouverture) + 450 (T4 ligne 512X D) = 1450.00
    expect($solde)->toBe(1450.00);
});

// ===========================================================================
// H — CompteBancaire sans IBAN : skip silencieux, pas d'exception
// ===========================================================================

test('[PD-H] calculerSoldePointage retourne solde_ouverture quand CompteBancaire sans IBAN (skip silencieux)', function () {
    // Créer un compte bancaire sans IBAN
    $compteSansIban = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => null,
        'solde_initial' => 500.00,
        'date_solde_initial' => '2025-09-01',
    ]);

    // Rapprochement sur ce compte sans IBAN
    $rapprochement = $this->service->create($compteSansIban, '2025-10-31', 700.00);

    // Aucune transaction pointée — vérifier que calculerSoldePointage ne lève pas d'exception
    // et retourne simplement solde_ouverture (500.00)
    $solde = null;
    $exception = null;

    try {
        $solde = $this->service->calculerSoldePointage($rapprochement->fresh());
    } catch (Throwable $e) {
        $exception = $e;
    }

    expect($exception)->toBeNull('calculerSoldePointage ne doit pas lever d\'exception si IBAN null')
        ->and($solde)->toBe(500.00, 'Le solde doit être égal à solde_ouverture quand le compte 512X est introuvable');
});

test('[PD-H2] calculerSoldePointage avec IBAN mais sans compte 512X correspondant : retourne solde_ouverture', function () {
    // Compte bancaire avec IBAN mais sans Compte PCG 512X associé (tenant sans schéma PD)
    $ibanSansPcg = 'FR7600000000000000000000001';
    $compteSansPcg = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $ibanSansPcg,
        'solde_initial' => 800.00,
        'date_solde_initial' => '2025-09-01',
    ]);
    // NE PAS appeler BancairesSeeder::seed() → pas de Compte PCG créé pour cet IBAN

    $rapprochement = $this->service->create($compteSansPcg, '2025-10-31', 900.00);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());

    // Pas de compte 512X trouvé → skip silencieux, retourne solde_ouverture seul
    expect($solde)->toBe(800.00);
});

// ===========================================================================
// I — AC8 : pointer un virement en_attente (Fix D)
// toggleTransaction doit : 1) générer T2 (512X), 2) mettre statut = Pointe,
//   3) poser rapprochement_id sur T2 aussi, 4) calculerSoldePointage bouge.
// Dé-pointer revient proprement à l'état antérieur.
// ===========================================================================

/**
 * Crée une T1 créance virement (411D/706C) sans encaissement — statut en_attente.
 */
function pdCreanceVirement(
    CompteBancaire $compteBancaire,
    Compte $compte706,
    Compte $compte411,
    Tiers $tiers,
    float $montant,
): Transaction {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Virement,
        'montant_total' => $montant,
        'compte_id' => $compteBancaire->id,
        'statut_reglement' => StatutReglement::EnAttente->value,
        'equilibree' => true,
        'type_ecriture' => 'normale',
    ]);

    // 411 D tiers (créance)
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte411->id,
        'debit' => $montant,
        'credit' => 0,
        'tiers_id' => $tiers->id,
        'libelle' => 'Créance virement en attente',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);
    // 706 C
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte706->id,
        'debit' => 0,
        'credit' => $montant,
        'tiers_id' => null,
        'libelle' => 'Créance virement en attente',
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    return $tx;
}

test('[PD-I1] toggleTransaction pointe virement en_attente → T2 généré, statut Pointe, solde bouge', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1300.00);

    $compte411 = compteSysteme('411');
    $t1 = pdCreanceVirement($this->compteBancaire, $this->compte706, $compte411, $this->tiers, 300.00);

    // Vérifier : pas de T2 ni de ligne 512X avant pointage
    expect(TransactionLigne::where('compte_id', $this->compte512X->id)->count())->toBe(0);
    $soldeAvant = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($soldeAvant)->toBe(1000.00, 'Solde avant pointage = ouverture');

    // Action : pointer la transaction en_attente
    $this->service->toggleTransaction($rapprochement, 'recette', $t1->id);

    // 1. Statut = Pointe
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::Pointe);
    expect((int) $t1->rapprochement_id)->toBe((int) $rapprochement->id);

    // 2. T2 créé : une transaction distincte porte une ligne 512X D
    $t2 = Transaction::where('association_id', TenantContext::currentId())
        ->where('id', '!=', $t1->id)
        ->whereHas('lignes', function ($q) {
            $q->where('compte_id', $this->compte512X->id)->where('debit', '>', 0);
        })
        ->first();
    expect($t2)->not->toBeNull('T2 (encaissement virement) doit être créé');
    expect((float) $t2->montant_total)->toBe(300.00);

    // 3. La paire 411 de T1 est lettrée
    $ligne411 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)->first();
    expect($ligne411->lettrage_code)->not->toBeNull('411 de T1 doit être lettrée');

    // 4. T2 porte aussi rapprochement_id (pour que la ligne 512X soit comptée)
    $t2->refresh();
    expect((int) $t2->rapprochement_id)->toBe((int) $rapprochement->id);

    // 5. calculerSoldePointage bouge du bon montant
    $soldeFinal = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($soldeFinal)->toBe(1300.00, '1000 + 300 = 1300');
});

test('[PD-I2] dé-pointer virement en_attente préalablement pointé → retour propre à l\'état antérieur', function () {
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1300.00);

    $compte411 = compteSysteme('411');
    $t1 = pdCreanceVirement($this->compteBancaire, $this->compte706, $compte411, $this->tiers, 300.00);

    // D'abord pointer
    $this->service->toggleTransaction($rapprochement, 'recette', $t1->id);

    // Récupérer T2 créé
    $t2 = Transaction::where('association_id', TenantContext::currentId())
        ->where('id', '!=', $t1->id)
        ->whereHas('lignes', function ($q) {
            $q->where('compte_id', $this->compte512X->id)->where('debit', '>', 0);
        })
        ->first();
    expect($t2)->not->toBeNull();
    expect((int) $t2->rapprochement_id)->toBe((int) $rapprochement->id);

    $soldeMilieu = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($soldeMilieu)->toBe(1300.00);

    // Dé-pointer
    $this->service->toggleTransaction($rapprochement->fresh(), 'recette', $t1->id);

    // Chantier 4 — statut dérivé : T2 (encaissement) existe avec ligne 512X et rapprochement_id=null.
    // Le resolver voit la trésorerie en banque non rapprochée → Recu (argent reçu, pas encore rapproché).
    // Avant chantier 4 : réinitialisation manuelle à EnAttente. Avec dérivation, Recu est correct.
    $t1->refresh();
    expect($t1->rapprochement_id)->toBeNull();
    expect($t1->statut_reglement)->toBe(StatutReglement::Recu);

    // T2 : rapprochement_id effacé (mais T2 CONSERVÉ — encaissement irréversible)
    $t2->refresh();
    expect($t2->rapprochement_id)->toBeNull('T2 rapprochement_id doit être effacé au dé-pointage');
    // T2 doit encore exister (pas de suppression)
    expect(Transaction::find($t2->id))->not->toBeNull('T2 ne doit pas être supprimé au dé-pointage');

    // Solde revenu à l'ouverture
    $soldeFinal = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($soldeFinal)->toBe(1000.00, 'Solde doit revenir à ouverture après dé-pointage');
});

test('[PD-I3] pointer virement déjà-lumped (512X sur T1) → idempotent, pas de T2 dupliqué', function () {
    // Transaction déjà enrichie (lumped : 512X sur la T1 elle-même) — cas backfill/legacy PD
    $rapprochement = $this->service->create($this->compteBancaire, '2025-10-31', 1300.00);

    $compte411 = compteSysteme('411');
    // Créer une tx avec les 4 lignes (cycle comptant, 411 déjà lettrée)
    $tx = pdRecette($this->compteBancaire, $this->compte512X, 300.00, $this->compte706, $compte411, $this->tiers, StatutReglement::Recu);

    // Lettrer manuellement la paire 411 pour simuler un T2 déjà généré
    // (511 déjà lettrée = idempotence guard dans encaisserSiNonEncaisse)
    $ligne411 = TransactionLigne::where('transaction_id', $tx->id)
        ->where('compte_id', $compte411->id)
        ->where('debit', '>', 0)
        ->first();
    $ligne411->update(['lettrage_code' => 'LTR-TEST-001']);
    $ligne411Bis = TransactionLigne::where('transaction_id', $tx->id)
        ->where('compte_id', $compte411->id)
        ->where('credit', '>', 0)
        ->first();
    $ligne411Bis->update(['lettrage_code' => 'LTR-TEST-001']);

    $nbTxAvant = Transaction::where('association_id', TenantContext::currentId())->count();

    // Pointer : la garde idempotente doit préserver — pas de nouveau T2
    $this->service->toggleTransaction($rapprochement, 'recette', $tx->id);

    $nbTxApres = Transaction::where('association_id', TenantContext::currentId())->count();
    expect($nbTxApres)->toBe($nbTxAvant, 'Aucun T2 supplémentaire sur transaction déjà lettrée (lumped)');

    // La ligne 512X de T1 doit être comptée car rapprochement_id est sur T1
    $tx->refresh();
    expect((int) $tx->rapprochement_id)->toBe((int) $rapprochement->id);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($solde)->toBe(1300.00, '1000 + 300 = 1300 via ligne 512X de T1 lumped');
});
