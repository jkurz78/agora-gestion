<?php

declare(strict_types=1);

/**
 * Step — Chantier 1 : rapprochement liste/pointe sur le 512X strict du compte.
 *
 * Vérifie que RapprochementDetail::render() ne liste que les écritures portant
 * une ligne sur le 512X du compte du rapprochement (+ remises groupées) en mode PD.
 *
 * Cas testés :
 *   1. Recette 512X-A  → listée
 *   2. Chèque loose (5112, pas de 512X) → NON listée
 *   3. Remise (T4 porte 512X-A) → listée (1 ligne groupée)
 *   4. Dépense chèque émis (512X-A crédit) → listée
 *   5. Cross-compte : écriture avec 512X-B sur compte A → NON listée
 */

use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Livewire\RapprochementDetail;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Setup partagé
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

    // Compte bancaire A avec son IBAN fixe
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);

    // Seeder comptes 512X (compte A)
    BancairesSeeder::seed();

    // Résoudre le compte 512X du compte bancaire A via compte_bancaire_id (même logique que le service)
    $this->compte512X = Compte::where('compte_bancaire_id', $this->compteBancaire->id)
        ->bancaires()
        ->firstOrFail();

    // Compte 706 (classe 7) pour les ventilations recette
    $categorieRec = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Prestations',
    ]);
    $this->sc706 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieRec->id,
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

    // Rapprochement en cours sur le compte A
    $this->rapprochement = RapprochementBancaire::create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'statut' => StatutRapprochement::EnCours,
        'date_fin' => '2026-08-31',
        'solde_ouverture' => 1000.00,
        'solde_fin' => 2000.00,
        'saisi_par' => $this->user->id,
    ]);
});

afterEach(function () {
    Config::set('compta.use_partie_double', false);
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Cas 1 — Recette virement 512X-A : doit apparaître dans la liste
// ---------------------------------------------------------------------------

it('[512X-1] recette virement portant une ligne 512X-A est listée dans le rappro (mode PD)', function () {
    // Construction manuelle : Transaction recette virement + ligne 512X D + ligne 706 C
    $txRecette = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Virement,
        'montant_total' => 300.00,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => true,
        'date' => '2026-07-10',
        'libelle' => 'Subvention 512X',
        'saisi_par' => $this->user->id,
    ]);
    // Ligne 512X D (encaissement trésorerie)
    TransactionLigne::create([
        'transaction_id' => $txRecette->id,
        'compte_id' => $this->compte512X->id,
        'debit' => 300.00,
        'credit' => 0,
        'libelle' => 'Recette 512X',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);
    // Ligne 706 C (contrepartie produit)
    TransactionLigne::create([
        'transaction_id' => $txRecette->id,
        'compte_id' => $this->compte706->id,
        'debit' => 0,
        'credit' => 300.00,
        'libelle' => 'Recette 512X',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);

    $component = Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement]);
    $rows = collect($component->viewData('transactions'));

    $found = $rows->contains(fn ($r) => $r['type'] === TypeTransaction::Recette->value && (int) $r['id'] === (int) $txRecette->id);
    expect($found)->toBeTrue('La recette virement portant une ligne 512X-A doit apparaître dans la liste rappro (mode PD)');
});

// ---------------------------------------------------------------------------
// Cas 2 — Chèque loose (portage 5112, pas de 512X) : NE doit PAS apparaître
// ---------------------------------------------------------------------------

it('[512X-2] chèque loose (5112, sans 512X) est exclu de la liste rappro (mode PD)', function () {
    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();

    $txCheque = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 150.00,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => true,
        'date' => '2026-07-12',
        'libelle' => 'Chèque loose sans 512X',
        'saisi_par' => $this->user->id,
    ]);
    // Ligne 5112 D (portage — pas de 512X)
    TransactionLigne::create([
        'transaction_id' => $txCheque->id,
        'compte_id' => $compte5112->id,
        'debit' => 150.00,
        'credit' => 0,
        'libelle' => 'Portage chèque',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);
    // Ligne 706 C
    TransactionLigne::create([
        'transaction_id' => $txCheque->id,
        'compte_id' => $this->compte706->id,
        'debit' => 0,
        'credit' => 150.00,
        'libelle' => 'Portage chèque',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);

    $component = Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement]);
    $rows = collect($component->viewData('transactions'));

    $found = $rows->contains(fn ($r) => (int) $r['id'] === (int) $txCheque->id);
    expect($found)->toBeFalse('Un chèque loose portant uniquement une ligne 5112 (sans 512X) ne doit PAS apparaître dans la liste (mode PD)');
});

// ---------------------------------------------------------------------------
// Cas 3 — Remise comptabilisée (T4 porte 512X-A) : doit apparaître en 1 ligne groupée
// ---------------------------------------------------------------------------

it('[512X-3] remise comptabilisée (T4 porte 512X-A) est listée comme 1 ligne groupée (mode PD)', function () {
    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();

    // Source chèque : Transaction recette chèque sur compte A (portage 5112, pas 512X)
    $txSource = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 100.00,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => true,
        'date' => '2026-07-05',
        'libelle' => 'Chèque source remise',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $txSource->id,
        'compte_id' => $compte5112->id,
        'debit' => 100.00,
        'credit' => 0,
        'libelle' => 'Portage chèque source',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);
    TransactionLigne::create([
        'transaction_id' => $txSource->id,
        'compte_id' => $this->compte706->id,
        'debit' => 0,
        'credit' => 100.00,
        'libelle' => 'Portage chèque source',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);

    // Comptabiliser la remise via le service → crée T4 avec ligne 512X
    $remise = app(RemiseBancaireService::class)->creer([
        'date' => '2026-07-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
    ]);
    app(RemiseBancaireService::class)->comptabiliser($remise, [(int) $txSource->id]);
    $remise->refresh();

    $component = Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement]);
    $rows = collect($component->viewData('transactions'));

    $found = $rows->contains(fn ($r) => $r['type'] === 'remise' && (int) $r['id'] === (int) $remise->id);
    expect($found)->toBeTrue('La remise comptabilisée (T4 porte 512X-A) doit apparaître comme 1 ligne groupée dans la liste rappro (mode PD)');
});

// ---------------------------------------------------------------------------
// Cas 4 — Dépense chèque émis (512X-A crédit) : doit apparaître
// ---------------------------------------------------------------------------

it('[512X-4] dépense portant une ligne 512X-A crédit est listée dans le rappro (mode PD)', function () {
    $compte401 = Compte::where('numero_pcg', '401')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();

    $txDepense = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Depense,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 200.00,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => true,
        'date' => '2026-07-20',
        'libelle' => 'Dépense chèque émis',
        'saisi_par' => $this->user->id,
    ]);
    // 601 D
    TransactionLigne::create([
        'transaction_id' => $txDepense->id,
        'compte_id' => $this->compte601->id,
        'debit' => 200.00,
        'credit' => 0,
        'libelle' => 'Dépense chèque',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);
    // 401 C
    TransactionLigne::create([
        'transaction_id' => $txDepense->id,
        'compte_id' => $compte401->id,
        'debit' => 0,
        'credit' => 200.00,
        'libelle' => 'Dépense chèque',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);
    // 401 D (soldage)
    TransactionLigne::create([
        'transaction_id' => $txDepense->id,
        'compte_id' => $compte401->id,
        'debit' => 200.00,
        'credit' => 0,
        'libelle' => 'Dépense chèque',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);
    // 512X C (décaissement)
    TransactionLigne::create([
        'transaction_id' => $txDepense->id,
        'compte_id' => $this->compte512X->id,
        'debit' => 0,
        'credit' => 200.00,
        'libelle' => 'Dépense chèque',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);

    $component = Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement]);
    $rows = collect($component->viewData('transactions'));

    $found = $rows->contains(fn ($r) => $r['type'] === TypeTransaction::Depense->value && (int) $r['id'] === (int) $txDepense->id);
    expect($found)->toBeTrue('La dépense portant une ligne 512X-A crédit doit apparaître dans la liste rappro (mode PD)');
});

// ---------------------------------------------------------------------------
// Cas 5 — Cross-compte : écriture avec 512X-B, compte_id=A → NE doit PAS apparaître
// ---------------------------------------------------------------------------

it('[512X-5] écriture portant 512X-B (autre compte) est exclue de la liste rappro du compte A (mode PD)', function () {
    // Créer un second compte bancaire B avec son propre 512X
    $ibanB = 'FR7699999000099999999901234';
    $compteBancaireB = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $ibanB,
        'solde_initial' => 500.00,
        'date_solde_initial' => '2025-09-01',
    ]);
    // Re-seeder pour créer le compte 512X de B
    BancairesSeeder::seed();

    $compte512XB = Compte::where('compte_bancaire_id', $compteBancaireB->id)
        ->bancaires()
        ->firstOrFail();

    // Transaction avec compte_id=A (passe le filtre legacy) mais ligne sur 512X-B
    $txCrossCompte = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'mode_paiement' => ModePaiement::Virement,
        'montant_total' => 400.00,
        'compte_id' => $this->compteBancaire->id,  // compte A !
        'equilibree' => true,
        'date' => '2026-07-25',
        'libelle' => 'Recette cross-compte 512X-B',
        'saisi_par' => $this->user->id,
    ]);
    // Ligne sur 512X-B (l'autre compte) — pas sur 512X-A
    TransactionLigne::create([
        'transaction_id' => $txCrossCompte->id,
        'compte_id' => $compte512XB->id,
        'debit' => 400.00,
        'credit' => 0,
        'libelle' => 'Cross 512X-B',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);
    TransactionLigne::create([
        'transaction_id' => $txCrossCompte->id,
        'compte_id' => $this->compte706->id,
        'debit' => 0,
        'credit' => 400.00,
        'libelle' => 'Cross 512X-B',
        'montant' => 0,
        'tiers_id' => null,
        'sous_categorie_id' => null,
    ]);

    $component = Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement]);
    $rows = collect($component->viewData('transactions'));

    $found = $rows->contains(fn ($r) => (int) $r['id'] === (int) $txCrossCompte->id);
    expect($found)->toBeFalse('Une écriture portant uniquement une ligne sur 512X-B ne doit PAS apparaître dans le rappro du compte A (mode PD) — le filtre doit utiliser le 512X strict du compte');
});
