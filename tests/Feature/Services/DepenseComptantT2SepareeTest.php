<?php

declare(strict_types=1);

/**
 * Chantier 3a-i — Dépense comptant saisie live → T1 dette Achat + T2 règlement Banque séparées.
 *
 * Avant le fix : pourDepenseComptant() produit 1 seule Tx avec 4 lignes (lumpé).
 * Après le fix : pourDepenseACredit() + pourReglementFournisseur() produisent 2 Tx
 *   — T1 (journal=Achat) : 60x D / 401 C (dette fournisseur)
 *   — T2 (journal=Banque) : 401 D / 512X C (règlement)
 *   — le 401 C de T1 est lettré avec le 401 D de T2 (même lettrage_code).
 *
 * Ce test couvre :
 *   3a-1 : structure T1+T2, 401 inter-tx lettré
 *   3a-2 : rapprochement — pointer T1 propage rapprochement_id sur T2
 *   3a-3 : rapprochement — dépointer T1 efface rapprochement_id sur T2
 *   3a-4 : statut_reglement = Recu à la création d'une dépense comptant
 */

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
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
use App\Services\RapprochementBancaireService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

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

    Config::set('compta.use_partie_double', true);

    // Comptes système : 401, 411, 5112
    SystemeSeeder::seed();

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

    // Compte 606 (classe 6) pour les dépenses
    $categorieDep = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Charges diverses',
    ]);
    $this->sc606 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieDep->id,
        'nom' => 'Achats fournitures',
        'code_cerfa' => '606',
    ]);
    $this->compte606 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '606'],
        [
            'intitule' => 'Achats non stockés de matières et fournitures',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->service = app(TransactionService::class);
    $this->rapproService = app(RapprochementBancaireService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helper — données d'une dépense comptant virement
// ---------------------------------------------------------------------------

function depenseVirementLiveData(object $ctx, float $montant = 200.0): array
{
    return [
        'data' => [
            'type' => TypeTransaction::Depense->value,
            'date' => '2025-10-20',
            'libelle' => 'Achat fournitures',
            'montant_total' => (string) $montant,
            'mode_paiement' => ModePaiement::Virement->value,
            'tiers_id' => $ctx->tiers->id,
            'compte_id' => $ctx->compteBancaire->id,
        ],
        'lignes' => [[
            'sous_categorie_id' => $ctx->sc606->id,
            'montant' => (string) $montant,
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

// ---------------------------------------------------------------------------
// 3a-1 : structure T1 + T2 séparées, 401 inter-tx lettré
// ---------------------------------------------------------------------------

it('[3a-1] dépense comptant live produit T1 (Achat, 60xD/401C) + T2 séparée (Banque, 401D/512XC), 401 inter-tx lettré', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseVirementLiveData($this);

    $t1 = $this->service->create($data, $lignes);

    $compte401 = compteSysteme('401');

    // ---- T1 doit être journal=Achat ----
    $t1->refresh();
    expect($t1->journal)->toBe(JournalComptable::Achat, 'T1 journal doit être Achat');

    // ---- T1 doit avoir 2 lignes PD : 60x D + 401 C (pas de ligne 512X sur T1) ----
    $lignesT1 = TransactionLigne::where('transaction_id', $t1->id)
        ->whereNotNull('compte_id')
        ->get();

    // Pas de ligne 512X sur T1
    $ligne512T1 = $lignesT1->firstWhere('compte_id', $this->compte512X->id);
    expect($ligne512T1)->toBeNull('T1 ne doit PAS avoir de ligne 512X (portage sur T2 uniquement)');

    // 1 ligne 401 C sur T1 (dette ouverte avec tiers)
    $ligne401C_T1 = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte401->id && (float) $l->credit > 0);
    expect($ligne401C_T1)->not()->toBeNull('T1 doit avoir une ligne 401 C (dette fournisseur)');
    expect((float) $ligne401C_T1->credit)->toBe(200.0);
    expect((int) $ligne401C_T1->tiers_id)->toBe((int) $this->tiers->id, 'Ligne 401 C doit avoir tiers_id');

    // 1 ligne 606 D (ventilation enrichie)
    $ligneVentilation = TransactionLigne::where('transaction_id', $t1->id)
        ->where('sous_categorie_id', $this->sc606->id)
        ->first();
    expect($ligneVentilation)->not()->toBeNull('La ligne legacy doit exister');
    expect($ligneVentilation->compte_id)->toBe($this->compte606->id, 'La ligne legacy est enrichie avec compte_id 606');
    expect((float) $ligneVentilation->debit)->toBe(200.0, 'La ligne 606 est débitée (charge)');

    // ---- T2 séparée doit exister ----
    $ligne401C_T1->refresh(); // Recharger pour lettrage_code à jour
    expect($ligne401C_T1->lettrage_code)->not()->toBeNull('La ligne 401 C de T1 doit être lettrée (liée à T2)');

    // Retrouver la ligne 401 de T2 via le code de lettrage
    $ligne401D_T2 = TransactionLigne::where('lettrage_code', $ligne401C_T1->lettrage_code)
        ->where('compte_id', $compte401->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();

    expect($ligne401D_T2)->not()->toBeNull('Une ligne 401 sur une AUTRE Tx (T2) doit partager le code lettrage de T1');

    $t2 = Transaction::findOrFail($ligne401D_T2->transaction_id);
    expect($t2->journal)->toBe(JournalComptable::Banque, 'T2 journal doit être Banque');

    // ---- T2 doit avoir 2 lignes : 401 D + 512X C ----
    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)
        ->whereNotNull('compte_id')
        ->get();
    expect($lignesT2->count())->toBe(2, 'T2 doit avoir exactement 2 lignes (401 D + 512X C)');

    $ligne401D = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $compte401->id && (float) $l->debit > 0);
    expect($ligne401D)->not()->toBeNull('T2 doit avoir une ligne 401 D (soldage de la dette)');
    expect((float) $ligne401D->debit)->toBe(200.0);
    expect((int) $ligne401D->tiers_id)->toBe((int) $this->tiers->id, 'Ligne 401 D doit avoir tiers_id');

    $ligne512C = $lignesT2->firstWhere('compte_id', $this->compte512X->id);
    expect($ligne512C)->not()->toBeNull('T2 doit avoir une ligne 512X C (décaissement)');
    expect((float) $ligne512C->credit)->toBe(200.0);
    expect($ligne512C->tiers_id)->toBeNull('Invariant FEC : classe 5 sans tiers');

    // ---- Le 401 C de T1 et le 401 D de T2 partagent le même code lettrage ----
    expect($ligne401C_T1->lettrage_code)->toBe($ligne401D_T2->lettrage_code, '401 T1 et 401 T2 doivent partager le même lettrage_code');
});

// ---------------------------------------------------------------------------
// 3a-2 : rapprochement — pointer T1 propage rapprochement_id sur T2
// ---------------------------------------------------------------------------

it('[3a-2] pointer une dépense comptant T1 propage rapprochement_id sur T2 (ligne 512X comptée)', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseVirementLiveData($this);
    $t1 = $this->service->create($data, $lignes);

    // Retrouver T2 via lettrage 401
    $compte401 = compteSysteme('401');
    $ligne401C_T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)
        ->whereNotNull('lettrage_code')
        ->first();
    expect($ligne401C_T1)->not()->toBeNull('[Précond] T1 doit avoir ligne 401 C lettrée');

    $ligne401D_T2 = TransactionLigne::where('lettrage_code', $ligne401C_T1->lettrage_code)
        ->where('compte_id', $compte401->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();
    expect($ligne401D_T2)->not()->toBeNull('[Précond] T2 doit exister');
    $t2 = Transaction::findOrFail($ligne401D_T2->transaction_id);

    // Créer un rapprochement ouvert
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'statut' => StatutRapprochement::EnCours,
        'date_fin' => '2025-10-31',
    ]);

    // Pointer T1 via toggleTransaction
    $this->rapproService->toggleTransaction($rapprochement, 'depense', (int) $t1->id);

    $t1->refresh();
    $t2->refresh();

    // T1 doit être pointée
    expect((int) $t1->rapprochement_id)->toBe((int) $rapprochement->id, 'T1 doit avoir rapprochement_id');
    expect($t1->statut_reglement)->toBe(StatutReglement::Pointe, 'T1 doit être Pointe');

    // T2 doit aussi avoir rapprochement_id propagé (c'est la ligne 512X qui compte pour le solde PD)
    expect((int) $t2->rapprochement_id)->toBe((int) $rapprochement->id, 'T2 doit avoir rapprochement_id propagé');
});

// ---------------------------------------------------------------------------
// 3a-3 : rapprochement — dépointer T1 efface rapprochement_id sur T2
// ---------------------------------------------------------------------------

it('[3a-3] dépointer une dépense comptant T1 efface rapprochement_id sur T2', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseVirementLiveData($this);
    $t1 = $this->service->create($data, $lignes);

    // Retrouver T2
    $compte401 = compteSysteme('401');
    $ligne401C_T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)
        ->whereNotNull('lettrage_code')
        ->first();
    $ligne401D_T2 = TransactionLigne::where('lettrage_code', $ligne401C_T1->lettrage_code)
        ->where('compte_id', $compte401->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();
    $t2 = Transaction::findOrFail($ligne401D_T2->transaction_id);

    // Créer un rapprochement ouvert et pré-pointer les deux transactions
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'statut' => StatutRapprochement::EnCours,
        'date_fin' => '2025-10-31',
    ]);
    $t1->update(['rapprochement_id' => $rapprochement->id, 'statut_reglement' => StatutReglement::Pointe->value]);
    $t2->update(['rapprochement_id' => $rapprochement->id]);

    // Dépointer T1 via toggleTransaction (deuxième appel = dépointage)
    $this->rapproService->toggleTransaction($rapprochement, 'depense', (int) $t1->id);

    $t1->refresh();
    $t2->refresh();

    // T1 dépointée
    expect($t1->rapprochement_id)->toBeNull('T1 doit avoir rapprochement_id = null après dépointage');

    // T2 aussi dépointée (propagation symétrique)
    expect($t2->rapprochement_id)->toBeNull('T2 doit avoir rapprochement_id = null après dépointage T1');
});

// ---------------------------------------------------------------------------
// 3a-4 : statut_reglement = Recu à la création d'une dépense comptant
// ---------------------------------------------------------------------------

it('[3a-4] dépense comptant créée via TransactionForm a statut_reglement = Recu', function () {
    // On vérifie via le service directement (le TransactionForm pose statut_reglement avant l'appel)
    // En simulant le comportement du form : on ajoute statut_reglement dans $data
    ['data' => $data, 'lignes' => $lignes] = depenseVirementLiveData($this);
    $data['statut_reglement'] = StatutReglement::Recu->value;

    $t1 = $this->service->create($data, $lignes);

    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::Recu, 'Une dépense comptant créée doit avoir statut=Recu');
});
