<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    // Alias : sc706 → scRecette (convention locale de ce fichier)
    $this->scRecette = $this->sc706;
    $this->categorieRecette = Categorie::where('association_id', $this->association->id)
        ->where('type', 'recette')->first();

    // Catégorie de charge + sous-catégorie dépense 606 (spécifiques TransactionService tests)
    $this->categorieDepense = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Charges diverses',
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
            'intitule' => 'Achats non stockés de matières et fournitures',
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
// Helpers locaux
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Scénario 1 : Recette comptant chèque
// ---------------------------------------------------------------------------

it('recette comptant chèque — T1 (Vente, 411D/7xxC) + T2 séparée (Banque, 5112D/411C), 411 inter-tx lettré', function () {
    // Chantier 2a : la saisie live d'une recette comptant produit désormais 2 transactions
    // séparées (T1 créance Vente + T2 encaissement Banque) au lieu d'un seul lumpé.
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Adhésion Jean Martin',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $t1 = $this->service->create($data, $lignes);

    $compte411 = compteSysteme('411');
    $compte5112 = compteSysteme('5112');

    // ---- T1 : 2 lignes avec compte_id (411 D + ventilation 706 C enrichie) ----
    // La ligne legacy est enrichie en place avec compte_id 706.
    $lignesT1 = TransactionLigne::where('transaction_id', $t1->id)
        ->whereNotNull('compte_id')
        ->get();
    expect($lignesT1->count())->toBe(2, 'T1 doit avoir 2 lignes PD : 411 D + 706 C');

    // 1 ligne 411 D sur T1 (créance ouverte)
    $ligne411D_T1 = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id && (float) $l->debit > 0);
    expect($ligne411D_T1)->not()->toBeNull('T1 doit avoir une ligne 411 D');
    expect((float) $ligne411D_T1->debit)->toBe(100.0);
    expect((int) $ligne411D_T1->tiers_id)->toBe((int) $this->tiers->id);

    // Pas de ligne 5112 sur T1
    $ligne5112T1 = $lignesT1->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112T1)->toBeNull('T1 ne doit PAS avoir de ligne 5112 (portage sur T2)');

    // La ligne legacy enrichie avec compte_id 706
    $ligneVentilation = TransactionLigne::where('transaction_id', $t1->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    expect($ligneVentilation)->not()->toBeNull('La ligne legacy doit exister');
    expect($ligneVentilation->compte_id)->toBe($this->compte706->id, 'La ligne legacy est enrichie avec compte_id 706');
    expect((float) $ligneVentilation->credit)->toBe(100.0, 'La ligne 706 est créditée (produit)');
    expect((float) $ligneVentilation->montant)->toBe(100.0, 'montant legacy conservé');

    // ---- T2 : 2 lignes (5112 D + 411 C), lettrage 411 T1↔T2 ----
    $ligne411D_T1->refresh();
    expect($ligne411D_T1->lettrage_code)->not()->toBeNull('La ligne 411 D de T1 doit être lettrée');

    $ligne411C_T2 = TransactionLigne::where('lettrage_code', $ligne411D_T1->lettrage_code)
        ->where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();
    expect($ligne411C_T2)->not()->toBeNull('La ligne 411 C de T2 doit exister et partager le code lettrage de T1');

    $t2 = Transaction::findOrFail($ligne411C_T2->transaction_id);

    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->get();
    expect($lignesT2->count())->toBe(2, 'T2 doit avoir 2 lignes : 5112 D + 411 C');

    $ligne5112T2 = $lignesT2->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112T2)->not()->toBeNull('T2 doit avoir une ligne 5112 D');
    expect((float) $ligne5112T2->debit)->toBe(100.0);
    expect($ligne5112T2->tiers_id)->toBeNull('Invariant FEC : classe 5 sans tiers');

    expect((float) $ligne411C_T2->credit)->toBe(100.0);
    expect((int) $ligne411C_T2->tiers_id)->toBe((int) $this->tiers->id);
});

// ---------------------------------------------------------------------------
// Scénario 2 : Recette à crédit (mode_paiement null)
// ---------------------------------------------------------------------------

it('recette à crédit — crée 2 lignes (1 ventilation enrichie + 411 D), pas de lettrage', function () {
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-11-01',
        'libelle' => 'Facture cotisation à recouvrer',
        'montant_total' => '75.00',
        'mode_paiement' => null,   // ← créance
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,   // pas de compte bancaire pour une créance
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => '75.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);

    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(2);

    $compte411 = compteSysteme('411');

    // 1 ligne 411 D (créance ouverte)
    $ligne411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->first();
    expect($ligne411)->not()->toBeNull();
    expect((float) $ligne411->debit)->toBe(75.0);
    expect((int) $ligne411->tiers_id)->toBe((int) $this->tiers->id);
    expect($ligne411->lettrage_code)->toBeNull('Créance ouverte — pas encore lettrée');

    // 1 ligne ventilation 706 C enrichie
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    expect($ligneVent)->not()->toBeNull();
    expect($ligneVent->compte_id)->toBe($this->compte706->id);
    expect((float) $ligneVent->credit)->toBe(75.0);
});

// ---------------------------------------------------------------------------
// Scénario 3 : Dépense comptant virement
// ---------------------------------------------------------------------------

it('dépense comptant virement — crée 4 lignes symétriques (lignes 401 auto-lettrées)', function () {
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-20',
        'libelle' => 'Achat fournitures',
        'montant_total' => '200.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scDepense->id,
        'montant' => '200.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);

    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(4);

    $compte401 = compteSysteme('401');

    // 2 lignes 401 (C puis D — schéma dépense comptant)
    $lignes401 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte401->id)
        ->get();
    expect($lignes401)->toHaveCount(2);

    $ligne401C = $lignes401->firstWhere(fn ($l) => (float) $l->credit > 0);
    $ligne401D = $lignes401->firstWhere(fn ($l) => (float) $l->debit > 0);
    expect($ligne401C)->not()->toBeNull();
    expect($ligne401D)->not()->toBeNull();
    expect((float) $ligne401C->credit)->toBe(200.0);
    expect((float) $ligne401D->debit)->toBe(200.0);

    // tiers_id sur les 2 lignes 401
    expect((int) $ligne401C->tiers_id)->toBe((int) $this->tiers->id);
    expect((int) $ligne401D->tiers_id)->toBe((int) $this->tiers->id);

    // Auto-lettrage interne 401
    expect($ligne401C->lettrage_code)->not()->toBeNull();
    expect($ligne401D->lettrage_code)->not()->toBeNull();
    expect($ligne401C->lettrage_code)->toBe($ligne401D->lettrage_code);

    // 1 ligne 512X C (trésorerie) sans tiers
    $ligne512 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $this->compte512->id)
        ->first();
    expect($ligne512)->not()->toBeNull();
    expect((float) $ligne512->credit)->toBe(200.0);
    expect($ligne512->tiers_id)->toBeNull('Invariant FEC : classe 5 sans tiers');

    // Ligne ventilation 606 D enrichie
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scDepense->id)
        ->first();
    expect($ligneVent)->not()->toBeNull();
    expect($ligneVent->compte_id)->toBe($this->compte606->id);
    expect((float) $ligneVent->debit)->toBe(200.0);
});

// ---------------------------------------------------------------------------
// Scénario 4 : Dépense à crédit (mode_paiement null)
// ---------------------------------------------------------------------------

it('dépense à crédit — crée 2 lignes symétriques, pas de lettrage', function () {
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-11-10',
        'libelle' => 'Facture fournisseur à payer',
        'montant_total' => '300.00',
        'mode_paiement' => null,  // ← dette
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scDepense->id,
        'montant' => '300.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);

    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(2);

    $compte401 = compteSysteme('401');

    // 1 ligne 401 C (dette ouverte)
    $ligne401 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte401->id)
        ->first();
    expect($ligne401)->not()->toBeNull();
    expect((float) $ligne401->credit)->toBe(300.0);
    expect((int) $ligne401->tiers_id)->toBe((int) $this->tiers->id);
    expect($ligne401->lettrage_code)->toBeNull('Dette ouverte — pas encore lettrée');

    // 1 ligne ventilation 606 D enrichie
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scDepense->id)
        ->first();
    expect($ligneVent)->not()->toBeNull();
    expect($ligneVent->compte_id)->toBe($this->compte606->id);
    expect((float) $ligneVent->debit)->toBe(300.0);
});

// ---------------------------------------------------------------------------
// Scénario 5 : Multi-ventilation (2 sous-catégories de recette)
// ---------------------------------------------------------------------------

it('multi-ventilation recette comptant chèque — T1 (411D/2×7xxC) + T2 séparée (5112D/411C agrégé)', function () {
    // Chantier 2a : multi-ventilation produit toujours T1+T2 séparées.
    // T1 : 1 ligne 411 D (total agrégé) + 2 ventilations légacy enrichies 7xx C
    // T2 : 1 ligne 5112 D (total agrégé) + 1 ligne 411 C (total agrégé)
    $scRecette2 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorieRecette->id,
        'nom' => 'Formations',
        'code_cerfa' => '706B',
    ]);
    $compte706B = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706B',
        'intitule' => 'Formations',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-25',
        'libelle' => 'Adhésion + Formation Jean Martin',
        'montant_total' => '150.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [
        [
            'sous_categorie_id' => $this->scRecette->id,
            'montant' => '100.00',
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ],
        [
            'sous_categorie_id' => $scRecette2->id,
            'montant' => '50.00',
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ],
    ];

    $t1 = $this->service->create($data, $lignes);

    $compte411 = compteSysteme('411');
    $compte5112 = compteSysteme('5112');

    // T1 : 3 lignes avec compte_id (411 D agrégé + 2 ventilations 7xx C)
    $lignesT1 = TransactionLigne::where('transaction_id', $t1->id)
        ->whereNotNull('compte_id')
        ->get();
    expect($lignesT1->count())->toBe(3, 'T1 doit avoir 3 lignes : 411 D + 2 ventilations 7xx C');

    // La ligne 411 D porte le montant total agrégé (150)
    $ligne411D = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id && (float) $l->debit > 0);
    expect($ligne411D)->not()->toBeNull('T1 doit avoir une ligne 411 D');
    expect((float) $ligne411D->debit)->toBe(150.0);

    // Pas de ligne 5112 sur T1
    $ligne5112T1 = $lignesT1->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112T1)->toBeNull('T1 ne doit PAS avoir de ligne 5112 (sur T2 uniquement)');

    // Les 2 lignes legacy sont enrichies avec leur compte_id respectif
    $ligneVent1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    $ligneVent2 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('sous_categorie_id', $scRecette2->id)
        ->first();

    expect($ligneVent1->compte_id)->toBe($this->compte706->id);
    expect((float) $ligneVent1->credit)->toBe(100.0);
    expect($ligneVent2->compte_id)->toBe($compte706B->id);
    expect((float) $ligneVent2->credit)->toBe(50.0);

    // T2 : 2 lignes (5112 D + 411 C), 411 lettré inter-tx
    $ligne411D->refresh();
    expect($ligne411D->lettrage_code)->not()->toBeNull('411 D de T1 doit être lettré vers T2');

    $ligne411C_T2 = TransactionLigne::where('lettrage_code', $ligne411D->lettrage_code)
        ->where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();
    expect($ligne411C_T2)->not()->toBeNull('T2 doit avoir une ligne 411 C lettrée');
    expect((float) $ligne411C_T2->credit)->toBe(150.0, 'T2 411 C doit avoir le total agrégé 150');

    $t2Id = $ligne411C_T2->transaction_id;
    $lignesT2 = TransactionLigne::where('transaction_id', $t2Id)->get();
    expect($lignesT2->count())->toBe(2, 'T2 doit avoir 2 lignes : 5112 D + 411 C');

    $ligne5112T2 = $lignesT2->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112T2)->not()->toBeNull('T2 doit avoir une ligne 5112 D');
    expect((float) $ligne5112T2->debit)->toBe(150.0);
    expect($ligne5112T2->tiers_id)->toBeNull('Invariant FEC : classe 5 sans tiers');
});

// ---------------------------------------------------------------------------
// Scénario 6 : Les lignes legacy (sous_categorie_id + montant) sont conservées
// ---------------------------------------------------------------------------

it('les champs legacy (sous_categorie_id et montant) sont conservés intacts après double écriture', function () {
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Test conservation legacy',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => 'Note test',
    ]];

    $transaction = $this->service->create($data, $lignes);

    // La ligne legacy doit avoir ses champs originaux intacts
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();

    expect($ligneVent)->not()->toBeNull();
    expect((int) $ligneVent->sous_categorie_id)->toBe((int) $this->scRecette->id);
    expect((float) $ligneVent->montant)->toBe(100.0, 'montant legacy conservé');
    expect($ligneVent->notes)->toBe('Note test', 'notes conservées');
});

// ---------------------------------------------------------------------------
// Scénario 7 : Skip silencieux si tiers_id est NULL
// ---------------------------------------------------------------------------

it('si tiers_id est null, la double écriture est ignorée (lignes legacy seules)', function () {
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette sans tiers',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => null,  // ← pas de tiers
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    // Ne doit pas lever d'exception
    $transaction = $this->service->create($data, $lignes);

    // Seulement 1 ligne legacy (pas de PD-only sans tiers)
    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(1);
});

// ---------------------------------------------------------------------------
// Scénario 8 : Skip silencieux si sous-catégorie sans code_cerfa
// ---------------------------------------------------------------------------

it('si sous-catégorie sans code_cerfa, la ligne legacy est créée mais pas enrichie', function () {
    $scSansCode = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorieRecette->id,
        'nom' => 'Divers sans code',
        'code_cerfa' => null,  // ← pas de code_cerfa
    ]);

    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette sous-cat sans code',
        'montant_total' => '30.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $scSansCode->id,
        'montant' => '30.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    // Ne doit pas lever d'exception
    $transaction = $this->service->create($data, $lignes);

    // La ligne legacy est créée, mais pas de PD-only (code_cerfa manquant → skip)
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $scSansCode->id)
        ->first();
    expect($ligneVent)->not()->toBeNull();
    expect($ligneVent->compte_id)->toBeNull('Sans code_cerfa, pas d\'enrichissement compte_id');

    // Aucune ligne 411 (skip de toute la double écriture car une ventilation manque son compte)
    $compte411 = compteSysteme('411');
    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->count();
    expect($lignes411)->toBe(0, 'Pas de ligne 411 si une ventilation ne peut pas être résolue');
});

// ---------------------------------------------------------------------------
// Scénario 9 : Dépense comptant chèque — portage sur 512X (pas 5112)
// Révèle l'issue #1 : avant fix, la ligne portage était écrite sur 5112 (faux).
// ---------------------------------------------------------------------------

it('dépense comptant chèque — la ligne portage est sur 512X, PAS sur 5112 (école 411 systématique)', function () {
    // Le compteBancaire et le compte512 (IBAN-matched 5121) sont créés dans beforeEach.
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-28',
        'libelle' => 'Achat fournitures par chèque',
        'montant_total' => '80.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,  // IBAN → compte5121
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scDepense->id,
        'montant' => '80.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);

    // La Tx doit avoir 4 lignes : 1 ventilation enrichie (606 D) + 401 C tiers + 401 D tiers + 512X C
    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(4);

    $compte401 = compteSysteme('401');
    $compte5112 = compteSysteme('5112');

    // 2 lignes 401 auto-lettrées
    $lignes401 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte401->id)
        ->get();
    expect($lignes401)->toHaveCount(2);

    $ligne401C = $lignes401->firstWhere(fn ($l) => (float) $l->credit > 0);
    $ligne401D = $lignes401->firstWhere(fn ($l) => (float) $l->debit > 0);
    expect($ligne401C)->not()->toBeNull();
    expect($ligne401D)->not()->toBeNull();

    // Auto-lettrage 401 actif
    expect($ligne401C->lettrage_code)->not()->toBeNull();
    expect($ligne401D->lettrage_code)->not()->toBeNull();
    expect($ligne401C->lettrage_code)->toBe($ligne401D->lettrage_code);

    // tiers_id sur les 2 lignes 401
    expect((int) $ligne401C->tiers_id)->toBe((int) $this->tiers->id);
    expect((int) $ligne401D->tiers_id)->toBe((int) $this->tiers->id);

    // Ligne portage : DOIT être sur 5121 (512X IBAN-matched), PAS sur 5112
    $ligne512 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $this->compte512->id)
        ->first();
    expect($ligne512)->not()->toBeNull('La ligne portage doit exister sur le compte 512X IBAN-matched');
    expect((float) $ligne512->credit)->toBe(80.0);
    expect($ligne512->tiers_id)->toBeNull('Invariant FEC : classe 5 sans tiers');

    // Aucune ligne sur 5112 (serait sémantiquement faux pour un chèque émis)
    $lignes5112 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte5112->id)
        ->count();
    expect($lignes5112)->toBe(0, 'Chèque émis → 512X direct, jamais 5112 (chèques reçus)');

    // Ligne ventilation 606 D enrichie
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scDepense->id)
        ->first();
    expect($ligneVent)->not()->toBeNull();
    expect($ligneVent->compte_id)->toBe($this->compte606->id);
    expect((float) $ligneVent->debit)->toBe(80.0);
});

// ---------------------------------------------------------------------------
// Scénario 10 (bonus issue #2) : mode comptant + compte_id null → skip gracieux
// ---------------------------------------------------------------------------

it('dépense comptant virement avec compte_id null — skip gracieux sans TypeError', function () {
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-11-15',
        'libelle' => 'Virement sans compte renseigné',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,  // ← compte_id null avec mode comptant
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scDepense->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    // Ne doit pas lever de TypeError ni d'exception — skip gracieux
    $transaction = $this->service->create($data, $lignes);

    // Seulement 1 ligne legacy (pas de PD-only car 512X introuvable)
    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(1, 'Skip gracieux : seulement la ligne legacy sans double écriture');
});

// ---------------------------------------------------------------------------
// Scénario 11 — Fix #1 : garde-fou XOR observer via chemin Eloquent
// Vérifie que l'enrichissement legacy déclenche bien l'observer saving()
// et que debit > 0 ET credit > 0 simultanément lève une InvalidArgumentException.
// ---------------------------------------------------------------------------

it('Fix #1 — enrichissement legacy déclenche observer XOR : debit ET credit > 0 lève une exception', function () {
    // On crée directement une TransactionLigne avec compte_id = null (ligne legacy),
    // puis on tente de l'enrichir via fill/save avec debit ET credit > 0.
    // L'observer TransactionLigneObserver::saving() doit intercepter la violation XOR.

    $transaction = Transaction::create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'date' => '2025-10-15',
        'libelle' => 'Recette test XOR',
        'montant_total' => 100.00,
        'mode_paiement' => ModePaiement::Cheque,
        'saisi_par' => $this->user->id,
        'equilibree' => false,
        'type_ecriture' => 'normale',
    ]);

    // Ligne legacy : compte_id null — l'observer l'ignore lors de la création
    $ligne = $transaction->lignes()->create([
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => 100.0,
    ]);
    expect($ligne->compte_id)->toBeNull('Ligne legacy créée sans compte_id — observer ne vérifie pas encore');

    // Tentative d'enrichissement avec debit > 0 ET credit > 0 (violation XOR)
    // L'observer saving() doit lever une InvalidArgumentException
    expect(fn () => $ligne->fill([
        'compte_id' => $this->compte706->id,
        'debit' => 100.0,
        'credit' => 50.0,  // ← violation XOR : les deux > 0
    ])->save())->toThrow(
        InvalidArgumentException::class,
        "viole l'invariant partie double"
    );
});

// ---------------------------------------------------------------------------
// Scénario 12 — Fix #4-B : notes propagées sur les lignes de ventilation PD-only
// Vérifie que notes saisies sur la ligne legacy (path TransactionService)
// est bien copiée sur la ligne de ventilation lors d'une recette via EcritureGenerator.
// ---------------------------------------------------------------------------

it('Fix #4-B — notes propagées sur la ligne de ventilation dans une recette à crédit via EcritureGenerator', function () {
    // On appelle directement EcritureGenerator (chemin PD-only sans existingTransaction)
    // pour vérifier que notes est transmis depuis la ventilation vers la ligne créée.

    $generator = app(EcritureGenerator::class);

    $ventilations = [[
        'compte' => $this->compte706,
        'montant' => 80.0,
        'operation_id' => null,
        'seance' => null,
        'notes' => 'Remboursement frais déplacement participant',
    ]];

    $transaction = $generator->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: $ventilations,
        dateConstatation: new DateTimeImmutable('2025-11-01'),
        libelle: 'Recette test notes',
    );

    // La ligne de ventilation (706 C) doit avoir notes renseignée
    $ligneVent = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $this->compte706->id)
        ->first();

    expect($ligneVent)->not()->toBeNull('La ligne de ventilation 706 doit exister');
    expect($ligneVent->notes)->toBe(
        'Remboursement frais déplacement participant',
        'Fix #4-B : notes propagées sur la ligne de ventilation PD-only'
    );

    // Les lignes techniques (411) ne doivent PAS avoir de notes
    $compte411 = compteSysteme('411');
    $ligne411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->first();
    expect($ligne411)->not()->toBeNull();
    expect($ligne411->notes)->toBeNull('Les lignes techniques 411 ne portent pas de notes métier');
});
