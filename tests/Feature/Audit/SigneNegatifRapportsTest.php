<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 2 : tests de régression sommations rapports.
 *
 * Vérifie que les builders de rapports, dashboards, écrans Livewire et exports
 * gèrent correctement un dataset mixte positif/négatif sans `abs()` indu ni
 * filtre `WHERE montant > 0` injustifié.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.1
 */

use App\Enums\RoleSysteme;
use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Livewire\Dashboard;
use App\Livewire\Exercices\ClotureWizard;
use App\Livewire\RapportCompteResultat;
use App\Livewire\SuperAdmin\Dashboard as SuperAdminDashboard;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Services\RapprochementBancaireService;
use App\Tenant\TenantContext;
use Livewire\Livewire;
use Tests\Support\Concerns\MakesAuditTransactions;

uses(MakesAuditTransactions::class);

// ── Fixtures shared via beforeEach ────────────────────────────────────────────

beforeEach(function () {
    $this->association = Association::factory()->create();
    // Comptes système (dont 120/129) requis par ANouveauPreviewBuilder : la
    // garde « Aperçu AN » de ClotureCheckService, désormais inconditionnelle,
    // les recherche dès que le résultat de l'exercice est non nul.
    SystemeSeeder::seed();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->sc = Compte::factory()->numero('706')->create();

    // Compte bancaire réel
    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'solde_initial' => 0.0,
    ]);

    // Exercice 2025 ouvert, session active
    $this->exercice = Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Complète l'écriture à une seule ligne produite par makeAuditTransaction()
 * par une contrepartie sur le compte bancaire.
 *
 * makeAuditTransaction() ne crée que la ligne de ventilation (706/606), ce
 * qui suffisait tant que la garde « Aperçu AN » de ClotureCheckService restait
 * de complaisance derrière le flag. Devenue réelle, elle bâtit un aperçu des
 * à-nouveaux sur l'ensemble de l'exercice et le rejette si le grand livre ne
 * s'équilibre pas — une écriture à une seule ligne ne s'équilibre jamais.
 * Seuls les tests qui passent par ClotureWizard (5 et 6) ont besoin de cette
 * contrepartie ; les huit autres testent des sommations de rapports qui ne
 * déclenchent pas cette garde.
 */
function completerContrepartieBancaire(Transaction $transaction, Compte $compte512, string $type, float $montant): void
{
    $contribution = $type === 'depense' ? -$montant : $montant;

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte512->id,
        'debit' => $contribution > 0 ? $contribution : 0.0,
        'credit' => $contribution < 0 ? -$contribution : 0.0,
        'montant' => abs($montant),
    ]);
}

// ── Test 1 ────────────────────────────────────────────────────────────────────

it('compte_resultat_somme_correctement_les_negatifs', function () {
    // +80 et -80 dans la même compte → ∑ = 0
    $this->makeAuditTransaction('recette', 80.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -80.0, $this->sc, $this->compte, 2025);

    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2025);

    // Le compte doit avoir montant_n = 0
    $totalProduits = collect($result['produits'])->flatMap(
        fn ($famille) => collect($famille['comptes'])->pluck('montant_n')
    )->sum();

    expect($totalProduits)->toBe(0.0);
});

// ── Test 2 ────────────────────────────────────────────────────────────────────

it('flux_tresorerie_inclut_negatifs_pointes', function () {
    // Rapprochement verrouillé sur ce compte
    $rapprochement = RapprochementBancaire::create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'date_fin' => '2025-11-30',
        'solde_ouverture' => 0.0,
        'solde_fin' => -50.0,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
    ]);

    // Tx recette -50 € pointée
    $this->makeAuditTransaction('recette', -50.0, $this->sc, $this->compte, 2025, $rapprochement);

    $builder = app(FluxTresorerieBuilder::class);
    $data = $builder->fluxTresorerie(2025);

    // total_recettes doit valoir -50 (somme algébrique, pas abs)
    expect($data['synthese']['total_recettes'])->toBe(-50.0);

    // variation = recettes - dépenses = -50 - 0 = -50
    expect($data['synthese']['variation'])->toBe(-50.0);
});

// ── Test 3 ────────────────────────────────────────────────────────────────────

it('dashboard_kpis_somme_negatifs', function () {
    // +100 recette, -30 recette, +50 dépense
    $this->makeAuditTransaction('recette', 100.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -30.0, $this->sc, $this->compte, 2025);

    $compteDepense = Compte::factory()->depense()->numero('606')->create();
    $this->makeAuditTransaction('depense', 50.0, $compteDepense, $this->compte, 2025);

    $component = Livewire::test(Dashboard::class);

    // totalRecettes = 100 + (-30) = 70
    $component->assertViewHas('totalRecettes', 70.0);

    // totalDepenses = 50
    $component->assertViewHas('totalDepenses', 50.0);

    // soldeGeneral = 70 - 50 = 20
    $component->assertViewHas('soldeGeneral', 20.0);
});

// ── Test 4 ────────────────────────────────────────────────────────────────────

it('super_admin_dashboard_renders_with_negative_transactions_in_db', function () {
    // Le SuperAdmin\Dashboard ne contient pas de KPIs de transactions :
    // il compte seulement les associations (actif/suspendu/archive).
    // On vérifie qu'il rend sans erreur même si des transactions négatives
    // existent en base dans le tenant courant.
    $this->makeAuditTransaction('recette', -100.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('depense', -200.0, $this->sc, $this->compte, 2025);

    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);

    Livewire::actingAs($superAdmin)
        ->test(SuperAdminDashboard::class)
        ->assertOk()
        ->assertViewHas('kpiActifs')
        ->assertViewHas('kpiSuspendus')
        ->assertViewHas('kpiArchives');
});

// ── Test 5 ────────────────────────────────────────────────────────────────────

it('cloture_wizard_calcule_solde_ouverture_avec_negatifs', function () {
    // Tx recette -100 € : réduit totalRecettes, ce qui augmente soldeOuverture calculé
    // (formule : soldeReel - recettes + depenses - vIn + vOut)
    // equilibree: true — makeAuditTransaction ne crée que la ligne de
    // ventilation (pas de lignes PD 411/401/512) ; ce test exerce
    // computeFinancialSummary(), pas la complétude comptable, et sans ce
    // drapeau la nouvelle garde « Préalables comptables » de
    // ClotureCheckService bloquerait le passage à l'étape 2.
    $compte512 = Compte::create([
        'numero_pcg' => '512',
        'intitule' => 'Banque',
        'classe' => 5,
        'compte_bancaire_id' => $this->compte->id,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $tx = $this->makeAuditTransaction('recette', -100.0, $this->sc, $this->compte, 2025, overrides: ['equilibree' => true]);
    completerContrepartieBancaire($tx, $compte512, 'recette', -100.0);

    $component = Livewire::test(ClotureWizard::class)
        ->call('suite')   // step 1 → step 2
        ->assertSet('step', 2);

    $summary = $component->viewData('summary');

    // totalRecettes doit inclure le négatif : -100
    expect($summary['totalRecettes'])->toBe(-100.0);

    // resultat = totalRecettes - totalDepenses = -100 - 0 = -100
    expect($summary['resultat'])->toBe(-100.0);
});

// ── Test 6 ────────────────────────────────────────────────────────────────────

it('cloture_wizard_resultat_avec_dataset_mixte', function () {
    // +200 recette, -50 recette, +80 dépense
    // equilibree: true sur les trois — voir le commentaire du test précédent.
    $compte512 = Compte::create([
        'numero_pcg' => '512',
        'intitule' => 'Banque',
        'classe' => 5,
        'compte_bancaire_id' => $this->compte->id,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    $tx1 = $this->makeAuditTransaction('recette', 200.0, $this->sc, $this->compte, 2025, overrides: ['equilibree' => true]);
    completerContrepartieBancaire($tx1, $compte512, 'recette', 200.0);

    $tx2 = $this->makeAuditTransaction('recette', -50.0, $this->sc, $this->compte, 2025, overrides: ['equilibree' => true]);
    completerContrepartieBancaire($tx2, $compte512, 'recette', -50.0);

    $compteDepense = Compte::factory()->depense()->numero('606')->create();
    $tx3 = $this->makeAuditTransaction('depense', 80.0, $compteDepense, $this->compte, 2025, overrides: ['equilibree' => true]);
    completerContrepartieBancaire($tx3, $compte512, 'depense', 80.0);

    $component = Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->assertSet('step', 2);

    $summary = $component->viewData('summary');

    // totalRecettes = 200 + (-50) = 150
    expect($summary['totalRecettes'])->toBe(150.0);

    // totalDepenses = 80
    expect($summary['totalDepenses'])->toBe(80.0);

    // resultat = 150 - 80 = 70
    expect($summary['resultat'])->toBe(70.0);
});

// ── Test 7 ────────────────────────────────────────────────────────────────────

it('rapprochement_service_solde_avec_negatif', function () {
    // Rapprochement en cours, solde_ouverture = 500
    $rapprochement = RapprochementBancaire::create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'date_fin' => '2025-11-30',
        'solde_ouverture' => 500.0,
        'solde_fin' => 500.0,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);

    // Tx recette -50 € pointée au rapprochement.
    //
    // calculerSoldePointage() somme les lignes portées par le compte 512X du
    // compte bancaire : solde_ouverture + SUM(debit) - SUM(credit).
    // Une recette de -50 € porte donc une ligne 512X au crédit de 50 €.
    // Résultat attendu : 500 + (-50) = 450
    //
    // Le scope bancaires() exige numero_pcg LIKE '512_%' — « 512 » seul ne matche pas.
    $compte512X = Compte::create([
        'numero_pcg' => '5121',
        'intitule' => 'Banque',
        'classe' => 5,
        'compte_bancaire_id' => $this->compte->id,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $tx = $this->makeAuditTransaction('recette', -50.0, $this->sc, $this->compte, 2025, $rapprochement);
    completerContrepartieBancaire($tx, $compte512X, 'recette', -50.0);

    $service = app(RapprochementBancaireService::class);
    $solde = $service->calculerSoldePointage($rapprochement->fresh());

    expect($solde)->toBe(450.0);
});

// ── Test 8 ────────────────────────────────────────────────────────────────────

it('rapport_compte_resultat_livewire_render_dataset_mixte', function () {
    // +100 recette, -40 recette (même compte) → ∑ produits = 60
    $this->makeAuditTransaction('recette', 100.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -40.0, $this->sc, $this->compte, 2025);

    Livewire::test(RapportCompteResultat::class)
        ->assertOk()
        // Le composant ne doit pas lever d'exception
        ->assertSee('RÉSULTAT')
        // totalProduitsN = 100 + (-40) = 60 (somme algébrique, pas abs)
        ->assertViewHas('totalProduitsN', 60.0);
});

// ── Test 9 ────────────────────────────────────────────────────────────────────

it('rapport_export_controller_synthese_compte_resultat_avec_negatifs', function () {
    // +120 recette, -20 recette → produits = 100
    $this->makeAuditTransaction('recette', 120.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -20.0, $this->sc, $this->compte, 2025);

    // L'export XLSX doit retourner 200 OK et le bon content-type
    $this->get('/rapports/export/compte-resultat/xlsx?exercice=2025')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

// ── Test 10 (test croisé) ─────────────────────────────────────────────────────

it('compte_resultat_avec_transactions_negatives_ET_provisions_PCA', function () {
    // (a) Tx recette -50 € (future extourne Slice 1)
    $this->makeAuditTransaction('recette', -50.0, $this->sc, $this->compte, 2025);

    // (b) Enregistrement `provisions` legacy à montant négatif (PCA), créé
    // directement sans passer par ProvisionPDService : aucune écriture 681/781
    // n'existe pour cette provision. En partie double, seules les écritures
    // du grand livre alimentent le compte de résultat — ce record n'a donc
    // aucun effet sur le résultat, et ne provoque plus de double comptage.
    Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => TypeTransaction::Recette,
        'compte_id' => $this->sc->id,
        'libelle' => 'PCA Test',
        'montant' => -30.0,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
    ]);

    // Vérifier que le builder retourne les produits avec le négatif tx inclus
    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2025);

    $totalProduits = collect($result['produits'])->flatMap(
        fn ($famille) => collect($famille['comptes'])->pluck('montant_n')
    )->sum();

    // Tx seule contribue -50 au total des produits
    expect($totalProduits)->toBe(-50.0);

    // Vérifier que le composant Livewire n'explose pas avec les deux sources
    $component = Livewire::test(RapportCompteResultat::class)
        ->assertOk();

    $component->assertViewHas('totalProduitsN', -50.0);

    // resultatCourant = produits - charges = -50 - 0 = -50 : la provision
    // legacy sans écriture PD n'est pas comptée en sus.
    $component->assertViewHas('resultatCourant', -50.0);
});
