<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionService;
use App\Tenant\TenantContext;

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

    // Seed des comptes système : 411, 401, 5112 (+ 530 conditionnel)
    SystemeSeeder::seed();

    // 530 est conditionnel — forcer sa création pour les tests nécessitant espèces
    if (! Compte::where('numero_pcg', '530')->where('association_id', $this->association->id)->exists()) {
        Compte::create([
            'association_id' => $this->association->id,
            'numero_pcg' => '530',
            'intitule' => 'Caisse (espèces)',
            'classe' => 5,
            'lettrable' => true,
            'actif' => true,
            'est_systeme' => true,
            'pour_inscriptions' => false,
        ]);
    }

    // Catégorie de produit pour les sous-catégories
    $this->categorieRecette = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Cotisations',
    ]);

    // Catégorie de charge pour les sous-catégories
    $this->categorieDepense = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Charges diverses',
    ]);

    // Sous-catégorie de recette avec code_cerfa aligné sur un compte 706 existant
    $this->scRecette = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorieRecette->id,
        'nom' => 'Cotisations membres',
        'code_cerfa' => '706',
    ]);

    // Compte 706 correspondant dans la table comptes (créé par la migration ou manuellement ici)
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

    // Sous-catégorie de dépense avec code_cerfa aligné sur un compte 606
    $this->scDepense = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorieDepense->id,
        'nom' => 'Achats fournitures',
        'code_cerfa' => '606',
    ]);

    // Compte 606 correspondant dans la table comptes
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

    // Compte bancaire physique (CompteBancaire legacy) avec IBAN connu
    $ibanBanque = 'FR7630006000011234567890189';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $ibanBanque,
    ]);

    // Compte 512X correspondant dans la table comptes (seed step 4 fait ça automatiquement
    // mais en test il faut le créer manuellement car la migration seed est positionnelle)
    $this->compte512 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'iban' => $ibanBanque],
        [
            'numero_pcg' => '5121',
            'intitule' => 'Banque principale',
            'classe' => 5,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => true,
            'pour_inscriptions' => false,
            'iban' => $ibanBanque,
        ]
    );

    $this->service = app(TransactionService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/** Raccourci pour récupérer le compte système (411, 401, 5112, 530). */
function compte411(): Compte
{
    return Compte::where('numero_pcg', '411')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
}

function compte401(): Compte
{
    return Compte::where('numero_pcg', '401')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
}

function compte5112(): Compte
{
    return Compte::where('numero_pcg', '5112')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
}

// ---------------------------------------------------------------------------
// Scénario 1 : Recette comptant chèque
// ---------------------------------------------------------------------------

it('recette comptant chèque — crée 4 lignes PD + enrichit la ligne legacy (lignes 411 auto-lettrées)', function () {
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

    $transaction = $this->service->create($data, $lignes);

    // Recharger les lignes fraîches depuis la DB
    $transaction->load('lignes');

    // La Tx legacy a 1 ligne. Les 3 lignes PD-only s'ajoutent → total = 4 lignes sur cette Tx
    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)
        ->withTrashed()  // garde-fou SoftDeletes
        ->count();

    expect($totalLignes)->toBe(4);

    $compte411 = compte411();
    $compte5112 = compte5112();

    // 2 lignes sur 411 (D et C)
    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->get();
    expect($lignes411)->toHaveCount(2);

    $ligne411D = $lignes411->firstWhere(fn ($l) => (float) $l->debit > 0);
    $ligne411C = $lignes411->firstWhere(fn ($l) => (float) $l->credit > 0);
    expect($ligne411D)->not()->toBeNull();
    expect($ligne411C)->not()->toBeNull();
    expect((float) $ligne411D->debit)->toBe(100.0);
    expect((float) $ligne411C->credit)->toBe(100.0);

    // tiers_id sur les lignes 411
    expect((int) $ligne411D->tiers_id)->toBe((int) $this->tiers->id);
    expect((int) $ligne411C->tiers_id)->toBe((int) $this->tiers->id);

    // Auto-lettrage interne : les 2 lignes 411 partagent le même lettrage_code non null
    expect($ligne411D->lettrage_code)->not()->toBeNull();
    expect($ligne411C->lettrage_code)->not()->toBeNull();
    expect($ligne411D->lettrage_code)->toBe($ligne411C->lettrage_code);

    // 1 ligne sur 5112 (D) sans tiers — école 411 systématique, FEC-conformité
    $ligne5112 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte5112->id)
        ->first();
    expect($ligne5112)->not()->toBeNull();
    expect((float) $ligne5112->debit)->toBe(100.0);
    expect($ligne5112->tiers_id)->toBeNull('Invariant : la classe 5 ne porte jamais de tiers (FEC)');

    // La ligne legacy (sous_categorie_id + montant) doit être enrichie avec compte_id 706
    $ligneVentilation = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    expect($ligneVentilation)->not()->toBeNull('La ligne legacy doit exister');
    expect($ligneVentilation->compte_id)->toBe($this->compte706->id, 'La ligne legacy est enrichie avec compte_id 706');
    expect((float) $ligneVentilation->credit)->toBe(100.0, 'La ligne 706 est créditée (produit)');
    expect((float) $ligneVentilation->montant)->toBe(100.0, 'montant legacy conservé');
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

    $compte411 = compte411();

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

    $compte401 = compte401();

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

    $compte401 = compte401();

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

it('multi-ventilation recette comptant chèque — 2 lignes 7x et 1 ligne 411 D au total agrégé', function () {
    // Deuxième sous-catégorie de recette
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

    $transaction = $this->service->create($data, $lignes);

    // 2 lignes legacy + 3 lignes PD-only (411 D, 5112 D, 411 C) = 5 lignes total
    $totalLignes = TransactionLigne::where('transaction_id', $transaction->id)->count();
    expect($totalLignes)->toBe(5);

    $compte411 = compte411();

    // La ligne 411 D porte le montant total agrégé (150)
    $ligne411D = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->where('debit', '>', 0)
        ->first();
    expect((float) $ligne411D->debit)->toBe(150.0);

    // Les 2 lignes legacy sont enrichies avec leur compte_id respectif
    $ligneVent1 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $this->scRecette->id)
        ->first();
    $ligneVent2 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('sous_categorie_id', $scRecette2->id)
        ->first();

    expect($ligneVent1->compte_id)->toBe($this->compte706->id);
    expect((float) $ligneVent1->credit)->toBe(100.0);
    expect($ligneVent2->compte_id)->toBe($compte706B->id);
    expect((float) $ligneVent2->credit)->toBe(50.0);
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
    $compte411 = compte411();
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

    $compte401 = compte401();
    $compte5112 = compte5112();

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
