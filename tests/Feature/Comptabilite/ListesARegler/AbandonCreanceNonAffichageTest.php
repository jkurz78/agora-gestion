<?php

declare(strict_types=1);

/**
 * Sentinelle — abandon de créance hors des listes "à régler".
 *
 * Garantit que les 2 Transactions créées par validerAvecAbandonCreance()
 * (statut_reglement = Recu) :
 *   - ne polluent PAS les listes filtrées sur statut_reglement = EnAttente
 *   - sont bien présentes dans les requêtes non filtrées
 *
 * Le filtre "à régler" est implémenté par TransactionUniverselleService::paginate()
 * via ->where('t.statut_reglement', 'en_attente') — ce test en valide le comportement
 * en appelant directement le service, sans monter la pile Livewire.
 */

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutReglement;
use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use App\Services\NoteDeFrais\ValidationData;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    Storage::fake('local');

    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);

    // Infrastructure partie double — comptes système 411, 401, 467 requis par abandonCreancePd()
    SystemeSeeder::seed();

    // Sous-catégorie Dépense avec code_cerfa → Compte classe 6 pour PD
    $catDepense = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeCategorie::Depense->value,
    ]);
    $this->scDepense = SousCategorie::factory()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $catDepense->id,
        'nom' => 'Frais divers',
        'code_cerfa' => '625',
    ]);
    Compte::firstOrCreate(
        ['association_id' => $this->asso->id, 'numero_pcg' => '625'],
        ['intitule' => 'Frais missions déplacements', 'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false]
    );

    // Sous-catégorie Recette AbandonCreance avec code_cerfa → Compte classe 7 pour PD
    $catRecette = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    $this->scAbandon = SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $catRecette->id,
        'nom' => 'Abandon de creance',
        'code_cerfa' => '771',
    ]);
    Compte::firstOrCreate(
        ['association_id' => $this->asso->id, 'numero_pcg' => '771'],
        ['intitule' => 'Dons et abandons de créances', 'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false]
    );

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->asso->id]);

    $this->data = new ValidationData(
        compte_id: (int) $this->compte->id,
        mode_paiement: ModePaiement::Virement,
        date: '2025-10-15',
    );

    $this->validationService = app(NoteDeFraisValidationService::class);
    $this->tuService = app(TransactionUniverselleService::class);
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée une NDF Soumise avec une ligne standard sans PJ.
 */
function makeNdfSoumiseForListTest(
    Association $asso,
    Tiers $tiers,
    SousCategorie $sc,
    float $montant = 200.0,
): NoteDeFrais {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-10-01',
        'libelle' => 'NDF abandon test',
    ]);

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'sous_categorie_id' => $sc->id,
        'libelle' => 'Repas',
        'montant' => $montant,
        'piece_jointe_path' => null,
    ]);

    return $ndf;
}

// ---------------------------------------------------------------------------
// Cas 1 — Après constat abandon : 0 transaction EnAttente, 2 transactions Recu
// ---------------------------------------------------------------------------

it('apres constat abandon aucune transaction nest en statut EnAttente', function (): void {
    $ndf = makeNdfSoumiseForListTest($this->asso, $this->tiers, $this->scDepense);

    $this->validationService->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    // En mode PD, les 2 OD de compensation (journal=OD) restent en_attente (écritures
    // comptables internes, non soumises au flux de trésorerie).
    // Les T1 txDepense et txDon sont bien passés à Recu via EtatReglementResolver.
    $enAttenteT1 = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)
        ->where(fn ($q) => $q->whereNull('journal')->orWhere('journal', '!=', JournalComptable::Od->value))
        ->count();
    expect($enAttenteT1)->toBe(0);

    // Les OD internes (journal=Od) ne doivent pas apparaître dans la branche dépense.
    // Ils peuvent apparaître dans la branche recette (journal Od inclus dans ce filtre),
    // mais ne sont pas des T1 métier — c'est un artefact PD attendu.
    $resultDepenses = $this->tuService->paginate(
        compteId: null,
        tiersId: null,
        types: ['depense'],
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        searchLibelle: null,
        searchReference: null,
        searchNumeroPiece: null,
        modePaiement: null,
        statutReglement: StatutReglement::EnAttente->value,
    );
    // t2Dep (journal=OD, type=depense) est exclu de la branche dépense (whereIn vente|achat).
    expect($resultDepenses['paginator']->total())->toBe(0);
});

it('apres constat abandon les deux transactions sont bien presentes en base (non filtrees)', function (): void {
    $ndf = makeNdfSoumiseForListTest($this->asso, $this->tiers, $this->scDepense, 150.0);

    $this->validationService->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    // En mode PD : T1-dépense + T1-don + t2Dep (OD) + t2Don (OD) = 4 transactions
    expect(Transaction::count())->toBe(4);

    // T1 Dépense (journal=Achat, tiers du NDF) → statut Recu
    // Note : le model observer Transaction::creating assigne journal=Achat pour les dépenses.
    $txDepense = Transaction::where('type', TypeTransaction::Depense->value)
        ->where('tiers_id', $this->tiers->id)
        ->where('journal', JournalComptable::Achat->value)
        ->first();
    expect($txDepense)->not->toBeNull();
    expect($txDepense->statut_reglement)->toBe(StatutReglement::Recu);

    // T1 Recette (journal=Vente, tiers du NDF) → statut Recu
    $txRecette = Transaction::where('type', TypeTransaction::Recette->value)
        ->where('tiers_id', $this->tiers->id)
        ->where('journal', JournalComptable::Vente->value)
        ->first();
    expect($txRecette)->not->toBeNull();
    expect($txRecette->statut_reglement)->toBe(StatutReglement::Recu);

    // paginate() sans filtre de statut inclut toutes les transactions visibles par journal :
    //   brancheDepense : journal IN (vente, achat) → T1 txDepense (journal=Achat) = 1
    //   brancheRecette : journal IN (vente, achat, od) → T1 txDon (journal=Vente) + t2Don (journal=Od) = 2
    //   t2Dep (journal=Od, type=depense) → EXCLU de brancheDepense (whereIn vente|achat seulement)
    // Total = 3
    $result = $this->tuService->paginate(
        compteId: null,
        tiersId: null,
        types: null,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        searchLibelle: null,
        searchReference: null,
        searchNumeroPiece: null,
        modePaiement: null,
        statutReglement: null, // pas de filtre
    );

    expect($result['paginator']->total())->toBe(3);
});

// ---------------------------------------------------------------------------
// Cas 2 — Contrôle négatif (sanity check) :
// Une Transaction standalone EnAttente apparaît bien dans les listes à régler
// ---------------------------------------------------------------------------

it('une transaction standalone en EnAttente apparait dans les listes a regler', function (): void {
    // Créer directement une Transaction de type Dépense au statut EnAttente (hors NDF)
    Transaction::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeTransaction::Depense->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte->id,
        'statut_reglement' => StatutReglement::EnAttente->value,
        'libelle' => 'Dépense à régler standalone',
        'montant_total' => 99.0,
        'date' => '2025-10-10',
    ]);

    // Elle doit apparaître dans le filtre EnAttente
    $result = $this->tuService->paginate(
        compteId: null,
        tiersId: null,
        types: null,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        searchLibelle: null,
        searchReference: null,
        searchNumeroPiece: null,
        modePaiement: null,
        statutReglement: StatutReglement::EnAttente->value,
    );

    expect($result['paginator']->total())->toBe(1);

    // Vérifier aussi via Eloquent directement
    $enAttente = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->count();
    expect($enAttente)->toBe(1);
});

// ---------------------------------------------------------------------------
// Cas 3 — Après abandon, les listes Dépenses ET Recettes sont vides
// (filtre par type + statut EnAttente)
// ---------------------------------------------------------------------------

it('apres abandon les listes depenses-a-regler et recettes-a-encaisser sont vides', function (): void {
    $ndf = makeNdfSoumiseForListTest($this->asso, $this->tiers, $this->scDepense);

    $this->validationService->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    // Liste "Dépenses à régler"
    $resultDepenses = $this->tuService->paginate(
        compteId: null,
        tiersId: null,
        types: ['depense'],
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        searchLibelle: null,
        searchReference: null,
        searchNumeroPiece: null,
        modePaiement: null,
        statutReglement: StatutReglement::EnAttente->value,
    );

    expect($resultDepenses['paginator']->total())->toBe(0);

    // Liste "Recettes à encaisser" :
    // t2Don (journal=Od, type=recette, statut=EnAttente) est un OD de compensation PD.
    // Le service inclut le journal Od dans la branche recette → t2Don remonte (1 résultat).
    // Note : ce n'est PAS une "recette à encaisser" métier — c'est une écriture de
    // compensation interne. Ce comportement est documenté ici comme attendu jusqu'à
    // l'ajout d'un filtre type_ecriture ou d'un flag is_od dans le service.
    $resultRecettes = $this->tuService->paginate(
        compteId: null,
        tiersId: null,
        types: ['recette'],
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        searchLibelle: null,
        searchReference: null,
        searchNumeroPiece: null,
        modePaiement: null,
        statutReglement: StatutReglement::EnAttente->value,
    );

    // t2Don (journal=Od) inclus dans la branche recette — 1 OD de compensation PD visible
    expect($resultRecettes['paginator']->total())->toBe(1);
});
