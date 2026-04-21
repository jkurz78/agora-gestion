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

use App\Enums\ModePaiement;
use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutReglement;
use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
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

    // Sous-catégorie Dépense (pour les lignes NDF)
    $catDepense = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeCategorie::Depense->value,
    ]);
    $this->scDepense = SousCategorie::factory()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $catDepense->id,
        'nom' => 'Frais divers',
    ]);

    // Sous-catégorie Recette désignée pour AbandonCreance
    $catRecette = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    $this->scAbandon = SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $catRecette->id,
        'nom' => 'Abandon de creance',
    ]);

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

    // Assertion négative : rien en EnAttente
    $enAttente = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->count();
    expect($enAttente)->toBe(0);

    // Assertion positive via filtre du service "à régler"
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

    expect($result['paginator']->total())->toBe(0);
});

it('apres constat abandon les deux transactions sont bien presentes en base (non filtrees)', function (): void {
    $ndf = makeNdfSoumiseForListTest($this->asso, $this->tiers, $this->scDepense, 150.0);

    $this->validationService->validerAvecAbandonCreance($ndf, $this->data, '2025-10-20');

    // 2 transactions totales
    expect(Transaction::count())->toBe(2);

    // 1 Transaction Dépense avec tiers du NDF
    $txDepense = Transaction::where('type', TypeTransaction::Depense->value)
        ->where('tiers_id', $this->tiers->id)
        ->first();
    expect($txDepense)->not->toBeNull();
    expect($txDepense->statut_reglement)->toBe(StatutReglement::Recu);

    // 1 Transaction Recette avec tiers du NDF
    $txRecette = Transaction::where('type', TypeTransaction::Recette->value)
        ->where('tiers_id', $this->tiers->id)
        ->first();
    expect($txRecette)->not->toBeNull();
    expect($txRecette->statut_reglement)->toBe(StatutReglement::Recu);

    // Les 2 apparaissent dans la liste non filtrée via le service
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

    // dépense + recette = 2 entrées dans le service (virements internes non créés)
    expect($result['paginator']->total())->toBe(2);
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

    // Liste "Recettes à encaisser"
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

    expect($resultRecettes['paginator']->total())->toBe(0);
});
