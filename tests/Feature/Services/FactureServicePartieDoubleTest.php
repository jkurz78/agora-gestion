<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\FactureService;
use Illuminate\Support\Facades\Log;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    // sc706 et compte706 sont exposés par setupPartieDoubleContext()
    // Sous-catégorie supplémentaire 758 (Produits divers) — spécifique FactureService tests
    $categoriePrestations = Categorie::where('association_id', $this->association->id)->first();
    $this->sc758 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categoriePrestations->id,
        'nom' => 'Produits divers',
        'code_cerfa' => '758',
    ]);
    $this->compte758 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '758'],
        [
            'intitule' => 'Produits divers de gestion courante',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // Tiers
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $this->service = app(FactureService::class);
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/** Crée une facture brouillon avec N lignes MontantManuel et la valide. */
function creerEtValiderFacture(
    object $ctx,
    array $lignesSpec,
    ?ModePaiement $modePaiement = ModePaiement::Virement,
): Facture {
    $facture = Facture::create([
        'association_id' => $ctx->association->id,
        'date' => '2025-11-15',
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $ctx->tiers->id,
        'saisi_par' => $ctx->user->id,
        'exercice' => 2025,
        'montant_total' => 0,
        'mode_paiement_prevu' => $modePaiement?->value,
    ]);

    foreach ($lignesSpec as $i => $spec) {
        FactureLigne::create([
            'facture_id' => $facture->id,
            'type' => TypeLigneFacture::MontantManuel->value,
            'sous_categorie_id' => $spec['sous_categorie_id'],
            'libelle' => $spec['libelle'] ?? 'Ligne '.($i + 1),
            'montant' => $spec['montant'],
            'ordre' => $i + 1,
        ]);
    }

    $ctx->service->valider($facture);

    return $facture->fresh();
}

// ---------------------------------------------------------------------------
// Scénario 1 : Facture avec 1 ligne MontantManuel → 2 lignes PD (706 C + 411 D)
// ---------------------------------------------------------------------------

it('facture 1 ligne MontantManuel → Transaction avec 2 lignes PD (706 C + 411 D tiers)', function () {
    $facture = creerEtValiderFacture($this, [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => 150.00, 'libelle' => 'Cotisation annuelle'],
    ], ModePaiement::Cheque);

    // 1 Transaction générée et attachée
    expect($facture->transactions()->count())->toBe(1);

    /** @var Transaction $tx */
    $tx = $facture->transactions()->first();
    expect($tx)->not->toBeNull();

    // Total lignes = 2 (1 ventilation 706 enrichie + 1 ligne 411 D PD-only)
    $lignes = TransactionLigne::where('transaction_id', $tx->id)->get();
    expect($lignes)->toHaveCount(2);

    $compte411 = compteSysteme('411');

    // Ligne 706 C — ventilation enrichie
    $ligne706 = $lignes->firstWhere('compte_id', $this->compte706->id);
    expect($ligne706)->not->toBeNull()
        ->and((float) $ligne706->credit)->toBe(150.0)
        ->and((float) $ligne706->debit)->toBe(0.0)
        ->and($ligne706->tiers_id)->toBeNull(); // FEC : pas de tiers sur 7x

    // Ligne 411 D — tiers, PD-only
    $ligne411 = $lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411)->not->toBeNull()
        ->and((float) $ligne411->debit)->toBe(150.0)
        ->and((float) $ligne411->credit)->toBe(0.0)
        ->and((int) $ligne411->tiers_id)->toBe((int) $this->tiers->id);

    // Équilibre : ∑ debit = ∑ credit
    $totalDebit = $lignes->sum(fn (TransactionLigne $l) => (float) $l->debit);
    $totalCredit = $lignes->sum(fn (TransactionLigne $l) => (float) $l->credit);
    expect($totalDebit)->toBe($totalCredit);
});

// ---------------------------------------------------------------------------
// Scénario 2 : Facture avec 2 lignes MontantManuel sur sous-catégories différentes
// → 3 lignes PD (2 ventilations 7x enrichies + 1 ligne 411 D agrégée)
// ---------------------------------------------------------------------------

it('facture 2 lignes MontantManuel → 3 lignes PD (2 ventilations + 1 ligne 411 D agrégée)', function () {
    $facture = creerEtValiderFacture($this, [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => 100.00, 'libelle' => 'Cotisation'],
        ['sous_categorie_id' => $this->sc758->id, 'montant' => 50.00, 'libelle' => 'Produit divers'],
    ]);

    $tx = $facture->transactions()->first();
    expect($tx)->not->toBeNull();

    $lignes = TransactionLigne::where('transaction_id', $tx->id)->get();
    expect($lignes)->toHaveCount(3);

    $compte411 = compteSysteme('411');

    // 2 ventilations 7x
    $ligne706 = $lignes->firstWhere('compte_id', $this->compte706->id);
    $ligne758 = $lignes->firstWhere('compte_id', $this->compte758->id);
    expect($ligne706)->not->toBeNull()
        ->and((float) $ligne706->credit)->toBe(100.0)
        ->and((float) $ligne706->debit)->toBe(0.0);
    expect($ligne758)->not->toBeNull()
        ->and((float) $ligne758->credit)->toBe(50.0)
        ->and((float) $ligne758->debit)->toBe(0.0);

    // 1 ligne 411 D agrégée (100 + 50 = 150)
    $ligne411 = $lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411)->not->toBeNull()
        ->and((float) $ligne411->debit)->toBe(150.0)
        ->and((float) $ligne411->credit)->toBe(0.0)
        ->and((int) $ligne411->tiers_id)->toBe((int) $this->tiers->id);

    // Équilibre
    $totalDebit = $lignes->sum(fn (TransactionLigne $l) => (float) $l->debit);
    $totalCredit = $lignes->sum(fn (TransactionLigne $l) => (float) $l->credit);
    expect($totalDebit)->toBe($totalCredit);
});

// ---------------------------------------------------------------------------
// Scénario 3 : Solde ouvert 411 du tiers = montant_total facture
// ---------------------------------------------------------------------------

it('solde ouvert 411 du tiers = montant_total facture (créance non lettrée)', function () {
    $facture = creerEtValiderFacture($this, [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => 200.00],
    ]);

    $compte411 = compteSysteme('411');

    // Lignes 411 du tiers, non lettrées
    $lignes411 = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->get();

    $soldeOuvert = $lignes411->sum(fn (TransactionLigne $l) => (float) $l->debit - (float) $l->credit);

    expect($soldeOuvert)->toBe(200.0);
});

// ---------------------------------------------------------------------------
// Scénario 4 : Lignes legacy conservées intactes + lien FactureLigne::transaction_ligne_id
// ---------------------------------------------------------------------------

it('lignes legacy conservées intactes avec sous_categorie_id, montant, notes et transaction_ligne_id', function () {
    $facture = creerEtValiderFacture($this, [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => 75.50, 'libelle' => 'Cotisation sportive'],
        ['sous_categorie_id' => $this->sc758->id, 'montant' => 24.50, 'libelle' => 'Produit secondaire'],
    ]);

    $tx = $facture->transactions()->first();
    $lignesLegacy = TransactionLigne::where('transaction_id', $tx->id)
        ->whereNotNull('sous_categorie_id') // les lignes legacy ont un sous_categorie_id
        ->get();

    expect($lignesLegacy)->toHaveCount(2);

    foreach ($lignesLegacy as $ligne) {
        expect($ligne->sous_categorie_id)->not->toBeNull();
        expect((float) $ligne->montant)->toBeGreaterThan(0.0);
    }

    // Les FactureLignes référencent leurs TransactionLignes respectives
    $factureLignes = FactureLigne::where('facture_id', $facture->id)
        ->where('type', TypeLigneFacture::MontantManuel->value)
        ->get();

    foreach ($factureLignes as $fl) {
        expect($fl->transaction_ligne_id)->not->toBeNull();
        $tl = TransactionLigne::find($fl->transaction_ligne_id);
        expect($tl)->not->toBeNull();
        // Les notes de la ligne PD = libelle de la FactureLigne
        expect($tl->notes)->toBe($fl->libelle);
    }
});

// ---------------------------------------------------------------------------
// Scénario 5 : Facture sans ligne MontantManuel → aucune Transaction générée
// (Seulement des lignes Montant ou Texte)
// ---------------------------------------------------------------------------

it('facture sans ligne MontantManuel → aucune Transaction générée', function () {
    // Facture avec uniquement des lignes de type Montant (issues de Transaction existante)
    $facture = Facture::create([
        'association_id' => $this->association->id,
        'date' => '2025-11-15',
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $this->tiers->id,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
        'montant_total' => 0,
        'mode_paiement_prevu' => ModePaiement::Virement->value,
    ]);

    // Crée une Transaction existante et l'attache (lignes type Montant)
    $txExistante = Transaction::create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'date' => '2025-11-15',
        'libelle' => 'Recette existante',
        'montant_total' => 80.00,
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'saisi_par' => $this->user->id,
    ]);

    $txLigne = TransactionLigne::create([
        'transaction_id' => $txExistante->id,
        'sous_categorie_id' => $this->sc706->id,
        'montant' => 80.00,
    ]);

    $this->service->ajouterTransactions($facture, [$txExistante->id]);
    $facture->refresh();

    // Valider — ne doit pas créer de Transaction supplémentaire
    $this->service->valider($facture);
    $facture->refresh();

    // La facture est attachée à 1 seule transaction (celle existante)
    expect($facture->transactions()->count())->toBe(1);
    expect($facture->transactions()->first()->id)->toBe($txExistante->id);
});

// ---------------------------------------------------------------------------
// Scénario 6 : FEC-conformité — aucune ligne classe 5 créée (créance pure)
// ---------------------------------------------------------------------------

it('FEC-conformité : aucune ligne classe 5 créée (créance pure, pas de trésorerie)', function () {
    // Même avec un mode_paiement_prevu renseigné, la validation crée une créance pure
    $facture = creerEtValiderFacture($this, [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => 300.00],
    ], ModePaiement::Virement);

    $tx = $facture->transactions()->first();

    $lignes = TransactionLigne::where('transaction_id', $tx->id)
        ->with('compte')
        ->get();

    // Aucune ligne sur un compte classe 5
    $lignesClasse5 = $lignes->filter(fn (TransactionLigne $l) => $l->compte !== null && (int) $l->compte->classe === 5);
    expect($lignesClasse5)->toBeEmpty();

    // Aucune ligne 7x ne porte un tiers_id
    $lignes7xAvecTiers = $lignes->filter(
        fn (TransactionLigne $l) => $l->compte !== null
            && (int) $l->compte->classe === 7
            && $l->tiers_id !== null
    );
    expect($lignes7xAvecTiers)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Scénario 7 : Pas de lettrage créé lors de la validation (créance ouverte)
// ---------------------------------------------------------------------------

it('aucun lettrage auto créé lors de la validation (la créance reste ouverte)', function () {
    $facture = creerEtValiderFacture($this, [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => 120.00],
    ]);

    $tx = $facture->transactions()->first();

    $lignes = TransactionLigne::where('transaction_id', $tx->id)->get();
    foreach ($lignes as $ligne) {
        expect($ligne->lettrage_code)->toBeNull();
    }
});

// ---------------------------------------------------------------------------
// Scénario 8 : Skip silencieux si sous-catégorie sans code_cerfa (I2 couverture)
// ---------------------------------------------------------------------------

it('facture ligne MontantManuel avec SC sans code_cerfa — Transaction créée, skip gracieux, pas de ligne 411', function () {
    Log::spy();

    // SC sans code_cerfa (skip attendu)
    $scSansCode = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => Categorie::where('association_id', $this->association->id)->first()->id,
        'nom' => 'Divers sans code cerfa',
        'code_cerfa' => null,
    ]);

    $facture = creerEtValiderFacture($this, [
        ['sous_categorie_id' => $scSansCode->id, 'montant' => 60.00, 'libelle' => 'Ligne sans code cerfa'],
    ]);

    // La facture passe bien en statut Validee (skip gracieux)
    expect($facture->statut)->toBe(StatutFacture::Validee);

    // Une Transaction a bien été générée
    expect($facture->transactions()->count())->toBe(1);
    $tx = $facture->transactions()->first();
    expect($tx)->not->toBeNull();

    // La ligne legacy est créée avec sous_categorie_id et montant intacts
    $ligneVent = TransactionLigne::where('transaction_id', $tx->id)
        ->where('sous_categorie_id', $scSansCode->id)
        ->first();
    expect($ligneVent)->not->toBeNull();
    expect($ligneVent->compte_id)->toBeNull('Pas d\'enrichissement compte_id sans code_cerfa');
    expect((float) $ligneVent->credit)->toBe(0.0, 'Pas de crédit enrichi sans code_cerfa');

    // Aucune ligne 411 PD-only (le skip arrête toute la double écriture)
    $compte411 = compteSysteme('411');
    $count411 = TransactionLigne::where('transaction_id', $tx->id)
        ->where('compte_id', $compte411->id)
        ->count();
    expect($count411)->toBe(0, 'Aucune ligne 411 si résolution compte échoue');

    // Le Log::warning a été émis (comportement documenté du skip)
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return str_contains($message, '[PartieDouble]') && str_contains($message, 'code_cerfa');
        });
});
