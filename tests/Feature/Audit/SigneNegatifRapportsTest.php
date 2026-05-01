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
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Services\RapprochementBancaireService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Crée une transaction (recette ou dépense) avec une seule ligne sur la
 * sous-catégorie donnée, dans l'exercice donné.
 * Le montant peut être négatif — aucune validation Eloquent ne l'interdit.
 */
function makeTransaction(
    string $type,
    float $montant,
    SousCategorie $sc,
    CompteBancaire $compte,
    int $exercice,
    ?RapprochementBancaire $rapprochement = null,
): Transaction {
    // L'exercice 2025 court du 2025-09-01 au 2026-08-31
    $date = "{$exercice}-10-15";

    $tx = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => $type,
        'date' => $date,
        'libelle' => "Test {$type} {$montant}",
        'montant_total' => $montant,
        'mode_paiement' => 'virement',
        'compte_id' => $compte->id,
        'statut_reglement' => 'en_attente',
        'saisi_par' => User::factory()->create()->id,
        'rapprochement_id' => $rapprochement?->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => $montant,
    ]);

    return $tx;
}

// ── Fixtures shared via beforeEach ────────────────────────────────────────────

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Catégorie / sous-catégorie de recette
    $this->categorie = Categorie::factory()->create(['association_id' => $this->association->id]);
    $this->sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);

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

// ── Test 1 ────────────────────────────────────────────────────────────────────

it('compte_resultat_somme_correctement_les_negatifs', function () {
    // +80 et -80 dans la même sous-cat → ∑ = 0
    makeTransaction('recette', 80.0, $this->sc, $this->compte, 2025);
    makeTransaction('recette', -80.0, $this->sc, $this->compte, 2025);

    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2025);

    // La sous-catégorie doit avoir montant_n = 0
    $totalProduits = collect($result['produits'])->flatMap(
        fn ($cat) => collect($cat['sous_categories'])->pluck('montant_n')
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
    makeTransaction('recette', -50.0, $this->sc, $this->compte, 2025, $rapprochement);

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
    makeTransaction('recette', 100.0, $this->sc, $this->compte, 2025);
    makeTransaction('recette', -30.0, $this->sc, $this->compte, 2025);

    $scDepense = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);
    makeTransaction('depense', 50.0, $scDepense, $this->compte, 2025);

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
    makeTransaction('recette', -100.0, $this->sc, $this->compte, 2025);
    makeTransaction('depense', -200.0, $this->sc, $this->compte, 2025);

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
    makeTransaction('recette', -100.0, $this->sc, $this->compte, 2025);

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
    makeTransaction('recette', 200.0, $this->sc, $this->compte, 2025);
    makeTransaction('recette', -50.0, $this->sc, $this->compte, 2025);

    $scDepense = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);
    makeTransaction('depense', 80.0, $scDepense, $this->compte, 2025);

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

    // Tx recette -50 € pointée au rapprochement
    // La formule calculerSoldePointage :
    //   solde_ouverture
    //   + SUM(CASE WHEN type='depense' THEN -montant_total ELSE montant_total END)
    // Pour une recette à -50 : contribution = -50
    // Résultat attendu : 500 + (-50) = 450
    makeTransaction('recette', -50.0, $this->sc, $this->compte, 2025, $rapprochement);

    $service = app(RapprochementBancaireService::class);
    $solde = $service->calculerSoldePointage($rapprochement->fresh());

    expect($solde)->toBe(450.0);
});

// ── Test 8 ────────────────────────────────────────────────────────────────────

it('rapport_compte_resultat_livewire_render_dataset_mixte', function () {
    // +100 recette, -40 recette (même sous-cat) → ∑ produits = 60
    makeTransaction('recette', 100.0, $this->sc, $this->compte, 2025);
    makeTransaction('recette', -40.0, $this->sc, $this->compte, 2025);

    Livewire::test(RapportCompteResultat::class)
        ->assertOk()
        // Le composant ne doit pas lever d'exception
        ->assertSee('RÉSULTAT');
});

// ── Test 9 ────────────────────────────────────────────────────────────────────

it('rapport_export_controller_synthese_compte_resultat_avec_negatifs', function () {
    // +120 recette, -20 recette → produits = 100
    makeTransaction('recette', 120.0, $this->sc, $this->compte, 2025);
    makeTransaction('recette', -20.0, $this->sc, $this->compte, 2025);

    // L'export XLSX doit retourner 200 OK et le bon content-type
    $this->get('/rapports/export/compte-resultat/xlsx?exercice=2025')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

// ── Test 10 (test croisé) ─────────────────────────────────────────────────────

it('compte_resultat_avec_transactions_negatives_ET_provisions_PCA', function () {
    // (a) Tx recette -50 € (future extourne Slice 1)
    makeTransaction('recette', -50.0, $this->sc, $this->compte, 2025);

    // (b) Provision de type recette à montant négatif (PCA — déjà supporté)
    // montantSigne() : type=recette → retourne montant tel quel = -30
    Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => TypeTransaction::Recette,
        'sous_categorie_id' => $this->sc->id,
        'libelle' => 'PCA Test',
        'montant' => -30.0,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
    ]);

    // Vérifier que le builder retourne les produits avec le négatif tx inclus
    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2025);

    $totalProduits = collect($result['produits'])->flatMap(
        fn ($cat) => collect($cat['sous_categories'])->pluck('montant_n')
    )->sum();

    // Tx seule contribue -50 au total des produits
    expect($totalProduits)->toBe(-50.0);

    // Vérifier que le composant Livewire n'explose pas avec les deux sources
    $component = Livewire::test(RapportCompteResultat::class)
        ->assertOk();

    // La provision PCA est gérée via totalProvisions (montantSigne = -30)
    // elle n'est PAS incluse dans les produits du builder (sources séparées)
    // Vérifier que les deux sources coexistent correctement
    $component->assertViewHas('totalProduitsN', -50.0);

    // totalProvisions = sum(montantSigne) = -30 pour une PCA recette
    $component->assertViewHas('totalProvisions', -30.0);

    // resultatCourant = produits - charges = -50 - 0 = -50
    $component->assertViewHas('resultatCourant', -50.0);

    // resultatNet = resultatBrut + totalProvisions
    // = resultatCourant + totalExtournes + totalProvisions
    // = (-50) + 0 + (-30) = -80
    $component->assertViewHas('resultatNet', -80.0);
});
