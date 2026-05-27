<?php

declare(strict_types=1);

/**
 * Step 31 (dette bloquante) — TransactionService::update couvert pour partie double.
 *
 * Problème : update() branche libre appelle forceDelete() sur toutes les lignes puis
 * recrée uniquement les lignes legacy — les lignes PD-only (411/401, 512X) sont perdues.
 * Résultat : invariant equilibree=true cassé, solde PD faux après update.
 *
 * Fix attendu :
 * - Branche libre : après recréation lignes legacy → appeler enrichirPartieDouble
 * - Branche Rappro-locked : patch ciblé compte_id sur la ligne de ventilation si
 *   sous_categorie_id change (montant gelé, lignes PD-only intactes)
 * - Branche Facture-locked : aucune ligne PD touchée (seul notes updatable)
 */

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Facture;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé (uses trait CreatesPartieDoubleContext — convention TenantTestCase)
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    // Alias : sc706 → scRecette (convention locale de ce fichier)
    $this->scRecette = $this->sc706;
    $this->categorieRecette = Categorie::where('association_id', $this->association->id)
        ->where('type', 'recette')->first();

    // Seconde sous-catégorie recette → compte 708 (pour tester changement de sous-catégorie)
    $this->scRecette2 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorieRecette->id,
        'nom' => 'Ventes diverses',
        'code_cerfa' => '708',
    ]);
    $this->compte708 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '708'],
        [
            'intitule' => 'Produits des activités annexes',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // Catégorie de charge + sous-catégorie dépense 606
    $this->categorieDepense = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
    ]);
    $this->scDepense = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorieDepense->id,
        'nom' => 'Achats fournitures',
        'code_cerfa' => '606',
    ]);
    $this->compte606 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '606'],
        [
            'intitule' => 'Achats non stockés',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // Tiers
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    // Alias : compte512X → compte512 (convention locale de ce fichier)
    $this->compte512 = $this->compte512X;

    $this->service = app(TransactionService::class);
});

// ---------------------------------------------------------------------------
// Helper — créer une recette comptant chèque via service::create (4 lignes PD)
// ---------------------------------------------------------------------------

function creerRecetteChequePD(object $ctx, float $montant = 100.0, ?SousCategorie $sc = null): Transaction
{
    $sc ??= $ctx->scRecette;
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation initiale',
        'montant_total' => (string) $montant,
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiers->id,
        'compte_id' => $ctx->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $sc->id,
        'montant' => (string) $montant,
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    return $ctx->service->create($data, $lignes);
}

// ---------------------------------------------------------------------------
// [A] Update libre — modifier le libellé : lignes PD recréées + equilibree=true
// ---------------------------------------------------------------------------

it('[A] update libre (non lockée) — lignes PD recréées après modification libellé', function () {
    $transaction = creerRecetteChequePD($this);

    // Vérifier que la transaction initiale est bien enrichie (4 lignes PD)
    $lignesInitiales = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($lignesInitiales)->toBe(4, 'create() produit 4 lignes PD');

    // Update libre : changer le libellé uniquement (même montant, même sous-catégorie)
    $transaction = $this->service->update($transaction, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation initiale — corrigée',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => null,
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // Après update : les lignes PD doivent être recréées (4 lignes)
    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(4, 'update() doit recréer 4 lignes PD (1 ventilation + 2 lignes 411 + 1 ligne 512X)');

    // Ligne portage recette chèque = 5112 (chèque reçu → valeurs en portefeuille)
    $compte5112 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '5112')->first();
    $lignePortage = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte5112->id)
        ->first();
    expect($lignePortage)->not()->toBeNull('Ligne portage 5112 doit être recréée après update (recette chèque)');
    expect((float) $lignePortage->debit)->toBe(100.0);

    // Ligne ventilation 706 présente + enrichie
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    expect($ligneVent)->not()->toBeNull('Ligne ventilation doit être recréée');
    expect($ligneVent->compte_id)->toBe($this->compte706->id, 'compte_id 706 enrichi');

    // 2 lignes 411 présentes avec lettrage auto
    $compte411 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '411')->first();
    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)->get();
    expect($lignes411)->toHaveCount(2, '2 lignes 411 recréées après update libre');
});

// ---------------------------------------------------------------------------
// [B] Update libre — changer sous_categorie_id : compte_id PD suit (706 → 708)
// ---------------------------------------------------------------------------

it('[B] update libre — changer sous_categorie_id → compte_id PD mis à jour (706 → 708)', function () {
    $transaction = creerRecetteChequePD($this);

    // Vérifier état initial : ventilation sur 706
    $ligneVentInit = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    expect($ligneVentInit->compte_id)->toBe($this->compte706->id, 'compte initial 706');

    // Update libre : changer sous_categorie_id de scRecette (706) vers scRecette2 (708)
    $transaction = $this->service->update($transaction, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation initiale',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => null,
        'sous_categorie_id' => $this->scRecette2->id,  // ← changé de 706 vers 708
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // La nouvelle ventilation doit pointer sur 708
    $ligneVentApres = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette2->id)
        ->first();
    expect($ligneVentApres)->not()->toBeNull('La ligne ventilation 708 doit exister après update');
    expect($ligneVentApres->compte_id)->toBe($this->compte708->id, 'compte_id doit être 708 après update');

    // Ligne 706 ne doit plus exister
    $ligneVent706 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    expect($ligneVent706)->toBeNull('Ligne 706 doit avoir disparu après update');

    // 4 lignes PD recréées avec nouvelle sous-catégorie
    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(4, '4 lignes PD recréées avec nouvelle sous-catégorie');

    // 2 lignes 411 recréées (lettrage auto sur recette chèque)
    $compte411 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '411')->first();
    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)->get();
    expect($lignes411)->toHaveCount(2, '2 lignes 411 recréées après changement sous-catégorie');

    // Ligne portage 5112 recréée (recette chèque)
    $compte5112 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '5112')->first();
    $lignePortage = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte5112->id)->first();
    expect($lignePortage)->not()->toBeNull('Ligne portage 5112 recréée après changement sous-catégorie');
});

// ---------------------------------------------------------------------------
// [C] Update Rappro-locked — changer sous_categorie_id : compte_id patché
// ---------------------------------------------------------------------------

it('[C] update Rappro-locked — changer sous_categorie_id → compte_id patché (montant gelé, lignes PD-only intactes)', function () {
    // Créer la transaction PD
    $transaction = creerRecetteChequePD($this);

    // Pointer dans un rapprochement verrouillé
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);
    $transaction->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => 'pointe']);
    $transaction->refresh();
    $transaction->load('lignes');

    // Récupérer la ligne de ventilation (sous_categorie_id = scRecette → compte 706)
    $ligneVent = $transaction->lignes->firstWhere('sous_categorie_id', $this->scRecette->id);
    expect($ligneVent)->not()->toBeNull('Ligne ventilation doit exister avant update');
    expect($ligneVent->compte_id)->toBe($this->compte706->id, 'compte initial 706');

    // Snapshot des lignes PD-only (411, 512X) pour vérifier qu'elles restent intactes
    $compte411 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '411')->first();
    $count411Avant = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)->count();
    expect($count411Avant)->toBe(2, '2 lignes 411 avant update Rappro-locked');

    // Construire le tableau complet des lignes (toutes les lignes existantes, IDs requis)
    // assertLockedInvariants vérifie count($lignes) == $existingLignes->count()
    $toutes = $transaction->lignes->map(fn ($l) => [
        'id' => $l->id,
        'sous_categorie_id' => $l->id === $ligneVent->id ? $this->scRecette2->id : $l->sous_categorie_id,
        'montant' => $l->montant,
        'operation_id' => $l->operation_id,
        'seance' => $l->seance,
        'notes' => $l->notes,
    ])->toArray();

    $transaction = $this->service->update($transaction, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation initiale',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], $toutes);

    // La ligne de ventilation doit avoir son compte_id mis à jour vers 708
    $ligneVentApres = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('id', $ligneVent->id)
        ->first();
    expect($ligneVentApres)->not()->toBeNull('Ligne ventilation intacte (pas effacée)');
    expect($ligneVentApres->compte_id)->toBe($this->compte708->id, 'compte_id patché vers 708');

    // Les lignes PD-only (411) doivent rester intactes
    $count411Apres = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)->count();
    expect($count411Apres)->toBe(2, '2 lignes 411 intactes après update Rappro-locked');
});

// ---------------------------------------------------------------------------
// [D] Update Facture-locked — changer notes seulement : aucune ligne PD touchée
// ---------------------------------------------------------------------------

it('[D] update Facture-locked — modifier notes uniquement → aucune ligne PD touchée, lettrage 411 intact', function () {
    // Créer la transaction PD
    $transaction = creerRecetteChequePD($this);
    $transaction->refresh();
    $transaction->load('lignes');

    // Lier à une facture validée via la table pivot facture_transaction
    $facture = Facture::factory()->validee()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);
    $transaction->factures()->attach($facture->id);
    $transaction->refresh();
    $transaction->load('lignes');

    expect($transaction->isLockedByFacture())->toBeTrue('La transaction doit être locked par la facture validée');

    // Snapshot lignes 411 + lettrage
    $compte411 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '411')->first();
    $lignes411Avant = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)->get();
    expect($lignes411Avant)->toHaveCount(2, '2 lignes 411 avant update Facture-locked');

    $lettrageAvant = $lignes411Avant->first()->lettrage_code;
    expect($lettrageAvant)->not()->toBeNull('Lettrage 411 doit être présent avant update');
    $idsAvant = $lignes411Avant->pluck('id')->sort()->values()->toArray();

    // Construire le tableau complet des lignes avec les IDs corrects
    // assertLockedByFactureInvariants vérifie : count($lignes) == existingLignes->count(),
    // même sous_categorie_id, même montant, seul notes peut changer
    $toutes = $transaction->lignes->map(fn ($l) => [
        'id' => $l->id,
        'sous_categorie_id' => $l->sous_categorie_id,
        'montant' => $l->montant,
        'operation_id' => $l->operation_id,
        'seance' => $l->seance,
        'notes' => $l->sous_categorie_id !== null ? 'Note ajoutée après validation' : $l->notes,
    ])->toArray();

    $transaction = $this->service->update($transaction, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation initiale',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], $toutes);

    // Les lignes 411 doivent être identiques (même IDs, même lettrage)
    $lignes411Apres = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)->get();
    expect($lignes411Apres)->toHaveCount(2, 'Toujours 2 lignes 411 après update Facture-locked');

    $idsApres = $lignes411Apres->pluck('id')->sort()->values()->toArray();
    expect($idsApres)->toBe($idsAvant, 'Les IDs des lignes 411 sont inchangés — aucun forceDelete');

    $lettrageApres = $lignes411Apres->first()->lettrage_code;
    expect($lettrageApres)->toBe($lettrageAvant, 'Lettrage 411 intact après update Facture-locked');

    // 4 lignes total inchangées
    $totalApres = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalApres)->toBe(4, '4 lignes total inchangées');
});

// ---------------------------------------------------------------------------
// [F] Update libre d'une recette comptant lettrée 411 (paire interne) :
//     auto-délettrage avant forceDelete, nouvelles lignes 411 non lettrées,
//     audit lettrage_audit avec action='delettre' et motif Auto-délettrage.
// ---------------------------------------------------------------------------

it('[F] update libre sur recette lettrée 411 (paire interne) — auto-délettrage + audit avant forceDelete', function () {
    // Créer une recette chèque PD (4 lignes : 1 ventilation 706, 2 lignes 411 lettrées, 1 portage 5112)
    $transaction = creerRecetteChequePD($this, 100.0);
    $transaction->refresh();
    $transaction->load('lignes');

    // Vérifier que les 2 lignes 411 sont bien lettrées (paire interne créée par EcritureGenerator)
    $compte411 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '411')->first();
    $lignes411Avant = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->get();
    expect($lignes411Avant)->toHaveCount(2, 'Précondition : 2 lignes 411 présentes');

    $lettrageCodeAvant = $lignes411Avant->first()->lettrage_code;
    expect($lettrageCodeAvant)->not()->toBeNull('Précondition : lignes 411 sont lettrées (paire interne)');

    // Aucune entrée delettre en audit avant l'update
    $auditAvant = \Illuminate\Support\Facades\DB::table('lettrage_audit')
        ->where('association_id', $this->association->id)
        ->where('action', 'delettre')
        ->count();
    expect($auditAvant)->toBe(0, 'Précondition : aucune entrée delettre en audit');

    // Update libre : changer le montant d'une ventilation → force re-enrichissement PD
    $transaction = $this->service->update($transaction, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation initiale',
        'montant_total' => '120.00',  // ← montant changé
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => null,
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => '120.00',  // ← montant changé
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // 1. Auto-délettrage : une entrée action='delettre' dans lettrage_audit avec motif auto-délettrage
    $auditAprès = \Illuminate\Support\Facades\DB::table('lettrage_audit')
        ->where('association_id', $this->association->id)
        ->where('action', 'delettre')
        ->first();
    expect($auditAprès)->not()->toBeNull('Un audit delettre doit être créé lors de l\'update');
    expect($auditAprès->lettrage_code)->toBe($lettrageCodeAvant, 'Le code lettrage audité est celui des 411 originales');
    expect($auditAprès->motif)->toContain('Auto-délettrage suite à update de TX#');

    // 2. Les nouvelles lignes 411 existent avec un NOUVEAU code de lettrage (différent de l'ancien)
    // Note : EcritureGenerator re-lettre automatiquement la paire interne 411D+411C à la création.
    // Ce qui compte, c'est que l'ancien code a disparu et qu'un nouveau code a été généré.
    $lignes411Après = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->get();
    expect($lignes411Après)->toHaveCount(2, '2 nouvelles lignes 411 recréées');

    foreach ($lignes411Après as $l) {
        expect($l->lettrage_code)->not()->toBe($lettrageCodeAvant, 'Les nouvelles lignes 411 portent un NOUVEAU code (l\'ancien a été déletté)');
    }

    // 3. 4 lignes PD recréées au total
    $total = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($total)->toBe(4, '4 lignes PD recréées après update sur montant changé');

    // 4. Montant total cohérent
    $transaction->refresh();
    expect((float) $transaction->montant_total)->toBe(120.0, 'Montant total mis à jour à 120');
});

// ---------------------------------------------------------------------------
// [G] Update Rappro-locked multi-lignes — N+1 : 2 ventilations patchées sans requête par ligne
// ---------------------------------------------------------------------------

it('[G] update Rappro-locked multi-lignes — 2 ventilations patchées, comptes mis à jour (N+1 fix)', function () {
    // Créer une recette chèque PD avec 2 lignes de ventilation (50+50 = 100)
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette multi-ventilations',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [
        ['sous_categorie_id' => $this->scRecette->id,  'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
        ['sous_categorie_id' => $this->scRecette2->id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ];
    $transaction = $this->service->create($data, $lignes);
    $transaction->refresh();
    $transaction->load('lignes');

    // Créer une 3ème sous-catégorie recette → compte 701
    $sc3 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorieRecette->id,
        'nom' => 'Cotisations membres',
        'code_cerfa' => '701',
    ]);
    Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '701'],
        ['intitule' => 'Ventes de produits finis', 'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false]
    );

    // Placer la transaction dans un rapprochement verrouillé
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);
    $transaction->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => 'pointe']);
    $transaction->refresh();
    $transaction->load('lignes');

    // Récupérer les deux lignes de ventilation
    $ligneVent706 = $transaction->lignes->firstWhere('sous_categorie_id', $this->scRecette->id);
    $ligneVent708 = $transaction->lignes->firstWhere('sous_categorie_id', $this->scRecette2->id);
    expect($ligneVent706)->not()->toBeNull('Ligne ventilation 706 doit exister');
    expect($ligneVent708)->not()->toBeNull('Ligne ventilation 708 doit exister');
    expect($ligneVent706->compte_id)->toBe($this->compte706->id, 'compte initial 706');
    expect($ligneVent708->compte_id)->toBe($this->compte708->id, 'compte initial 708');

    // Construire l'update : changer les deux sous_categories
    $toutes = $transaction->lignes->map(fn ($l) => [
        'id' => $l->id,
        'sous_categorie_id' => match ((int) $l->id) {
            (int) $ligneVent706->id => $this->scRecette2->id, // 706 → 708
            (int) $ligneVent708->id => $sc3->id,              // 708 → 701
            default => $l->sous_categorie_id,
        },
        'montant' => $l->montant,
        'operation_id' => $l->operation_id,
        'seance' => $l->seance,
        'notes' => $l->notes,
    ])->toArray();

    $transaction = $this->service->update($transaction, $data, $toutes);

    // Les deux lignes de ventilation doivent être patchées
    $ligneApres706 = TransactionLigne::find($ligneVent706->id);
    $ligneApres708 = TransactionLigne::find($ligneVent708->id);

    expect($ligneApres706->compte_id)->toBe($this->compte708->id, 'ligne ex-706 → compte 708');
    expect($ligneApres708->compte_id)->toBe(
        Compte::where('association_id', $this->association->id)->where('numero_pcg', '701')->first()?->id,
        'ligne ex-708 → compte 701'
    );

    // Lignes PD-only (411) restent intactes
    $compte411 = Compte::where('association_id', $this->association->id)->where('numero_pcg', '411')->first();
    $count411 = TransactionLigne::where('transaction_id', $transaction->id)->where('compte_id', $compte411->id)->count();
    expect($count411)->toBe(2, '2 lignes 411 intactes après update Rappro-locked multi-lignes');
});

// ---------------------------------------------------------------------------
// [E] Non-régression — update libre sans tiers : skip enrichissement PD (silencieux)
// ---------------------------------------------------------------------------

it('[E] update libre sans tiers_id — enrichissement PD skippé silencieusement (pas d\'exception)', function () {
    // Créer une transaction sans tiers (saisie libre) via factory directe
    $transaction = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Virement->value,
        'date' => '2025-10-01',
        'libelle' => 'Recette sans tiers',
        'tiers_id' => null,
        'saisi_par' => $this->user->id,
    ]);

    $sc = SousCategorie::factory()->create(['association_id' => $this->association->id]);
    $transaction->lignes()->create([
        'sous_categorie_id' => $sc->id,
        'montant' => 50.0,
    ]);

    // Update libre sans tiers → enrichirPartieDouble skip silencieux, pas d'exception
    $updated = $this->service->update($transaction, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-01',
        'libelle' => 'Recette sans tiers — modifiée',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => null,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => null,
        'sous_categorie_id' => $sc->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // Pas d'exception = succès. 1 seule ligne legacy (pas de PD car tiers_id null)
    $totalLignes = TransactionLigne::where('transaction_id', $updated->id)->count();
    expect($totalLignes)->toBe(1, 'Seulement la ligne legacy — pas de PD sans tiers_id');
    expect($updated->libelle)->toBe('Recette sans tiers — modifiée', 'Libellé mis à jour');
});
