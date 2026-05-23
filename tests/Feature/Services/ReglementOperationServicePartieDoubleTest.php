<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    // CompteBancaire + Compte 512X correspondant (via IBAN)
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
        'actif_recettes_depenses' => true,
    ]);
    BancairesSeeder::seed();
    $this->compte512X = Compte::where('iban', $this->iban)
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    // Catégorie + sous-catégorie 706
    $categorie = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Prestations',
    ]);
    $this->sc706 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
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

    // TypeOperation → Operation → Seance
    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'sous_categorie_id' => $this->sc706->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation PHP',
    ]);
    $this->seance = Seance::create([
        'association_id' => $this->association->id,
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2025-11-15',
    ]);

    $this->service = app(ReglementOperationService::class);
    $this->date = Carbon::parse('2025-11-15');
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée un participant + tiers + règlement pour la séance fournie.
 */
function creerParticipantEtReglement(
    object $ctx,
    ModePaiement $mode = ModePaiement::Cheque,
    float $montant = 120.00,
): Reglement {
    $tiers = Tiers::factory()->create(['association_id' => $ctx->association->id]);
    $participant = Participant::create([
        'association_id' => $ctx->association->id,
        'tiers_id' => (int) $tiers->id,
        'operation_id' => (int) $ctx->operation->id,
        'date_inscription' => now(),
    ]);

    return Reglement::create([
        'participant_id' => (int) $participant->id,
        'seance_id' => (int) $ctx->seance->id,
        'mode_paiement' => $mode->value,
        'montant_prevu' => $montant,
    ]);
}

// ---------------------------------------------------------------------------
// Scénario A : comptabiliserSeance crée N créances ouvertes avec lignes PD
// ---------------------------------------------------------------------------

it('[A] comptabiliserSeance crée 2 Transactions avec lignes PD (411 D + 706 C)', function () {
    // 2 participants, 2 règlements sans transaction
    $r1 = creerParticipantEtReglement($this, ModePaiement::Cheque, 120.00);
    $r2 = creerParticipantEtReglement($this, ModePaiement::Especes, 80.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    // 2 Transactions créées
    $txs = Transaction::where('type', TypeTransaction::Recette->value)
        ->where('statut_reglement', StatutReglement::EnAttente->value)
        ->get();
    expect($txs)->toHaveCount(2);

    $compte411 = compteSysteme('411');
    $compte706 = compteSysteme('706');

    foreach ($txs as $tx) {
        // Chaque tx a 2 lignes PD : 411 D + 706 C
        $lignes = TransactionLigne::where('transaction_id', $tx->id)->get();
        expect($lignes)->toHaveCount(2);

        $ligne411 = $lignes->firstWhere('compte_id', $compte411->id);
        $ligne706 = $lignes->firstWhere('compte_id', $compte706->id);

        expect($ligne411)->not->toBeNull();
        expect($ligne706)->not->toBeNull();

        // 411 D tiers (créance ouverte)
        expect((float) $ligne411->debit)->toBeGreaterThan(0.0);
        expect((float) $ligne411->credit)->toBe(0.0);
        expect($ligne411->tiers_id)->not->toBeNull();
        expect($ligne411->lettrage_code)->toBeNull(); // pas encore lettrée

        // 706 C sans tiers
        expect((float) $ligne706->credit)->toBeGreaterThan(0.0);
        expect((float) $ligne706->debit)->toBe(0.0);
        expect($ligne706->tiers_id)->toBeNull();

        // Pas de ligne 5xx (créance pure — pas d'encaissement)
        $ligne5xx = $lignes->filter(fn ($l) => $l->compte_id !== null &&
            Compte::find($l->compte_id)?->classe === 5
        );
        expect($ligne5xx)->toHaveCount(0);
    }
});

// ---------------------------------------------------------------------------
// Scénario B : marquerRecu sur une Tx créance crée T2 + auto-lettrage 411
// ---------------------------------------------------------------------------

it('[B] marquerRecu sur tx créance → T2 créée (5112 D / 411 C), auto-lettrage 411 actif', function () {
    $r1 = creerParticipantEtReglement($this, ModePaiement::Cheque, 120.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $t1 = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->firstOrFail();

    $compte411 = compteSysteme('411');
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBeNull();

    // Action : marquerRecu
    $this->service->marquerRecu($t1);
    $t1->refresh();

    // statut_reglement basculé sur T1
    expect($t1->statut_reglement)->toBe(StatutReglement::Recu);

    // T2 créée
    $countTxAfter = Transaction::count();
    expect($countTxAfter)->toBe(2); // T1 + T2

    $t2 = Transaction::where('id', '!=', $t1->id)->firstOrFail();
    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->get();
    expect($lignesT2)->toHaveCount(2);

    $compte5112 = compteSysteme('5112');

    // Portage 5112 D (chèque reçu → placeholder)
    $lignePortage = $lignesT2->firstWhere('compte_id', $compte5112->id);
    expect($lignePortage)->not->toBeNull();
    expect((float) $lignePortage->debit)->toBe(120.0);
    expect((float) $lignePortage->credit)->toBe(0.0);
    expect($lignePortage->tiers_id)->toBeNull(); // FEC : pas de tiers sur 5x

    // 411 C tiers sur T2
    $ligne411T2 = $lignesT2->firstWhere('compte_id', $compte411->id);
    expect($ligne411T2)->not->toBeNull();
    expect((float) $ligne411T2->credit)->toBe(120.0);
    expect((float) $ligne411T2->debit)->toBe(0.0);
    expect($ligne411T2->tiers_id)->not->toBeNull();

    // Auto-lettrage : T1.ligne411 et T2.ligne411 partagent le même code
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    expect($ligne411T1->lettrage_code)->not->toBeNull();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);
});

// ---------------------------------------------------------------------------
// Scénario C : flow complet — solde 411 = 0 après encaissement
// ---------------------------------------------------------------------------

it('[C] flow complet comptabiliserSeance + marquerRecu → solde 411 du tiers = 0', function () {
    $r1 = creerParticipantEtReglement($this, ModePaiement::Cheque, 100.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $t1 = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->firstOrFail();

    $compte411 = compteSysteme('411');
    $tiersId = (int) TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->value('tiers_id');

    // Avant encaissement : solde 411 = 100 D (créance ouverte)
    $soldeAvant = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $tiersId)
        ->selectRaw('SUM(debit) - SUM(credit) as solde')
        ->value('solde');
    expect((float) $soldeAvant)->toBe(100.0);

    // Encaissement
    $this->service->marquerRecu($t1);

    // Après encaissement : T1.411 D = 100 + T2.411 C = 100 → solde = 0
    $soldeApres = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $tiersId)
        ->selectRaw('SUM(debit) - SUM(credit) as solde')
        ->value('solde');
    expect((float) $soldeApres)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Scénario D : comptabiliserSeance mode_paiement prévu = Cheque → créance pure (sans 5xx)
// ---------------------------------------------------------------------------

it('[D] comptabiliserSeance Cheque → créance pure (411 D + 706 C, AUCUNE ligne 5xx)', function () {
    creerParticipantEtReglement($this, ModePaiement::Cheque, 150.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $tx = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->firstOrFail();
    $lignes = TransactionLigne::where('transaction_id', $tx->id)->get();

    // Exactement 2 lignes : 411 D + 706 C
    expect($lignes)->toHaveCount(2);

    // Aucune ligne sur un compte classe 5
    $classes5 = $lignes->filter(fn ($l) => $l->compte_id !== null &&
        Compte::find($l->compte_id)?->classe === 5
    );
    expect($classes5)->toHaveCount(0);

    $compte411 = compteSysteme('411');
    $ligne411 = $lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411)->not->toBeNull();
    expect((float) $ligne411->debit)->toBe(150.0);
    expect($ligne411->lettrage_code)->toBeNull();
});

// ---------------------------------------------------------------------------
// Scénario E : marquerRecu mode Virement → 512X D / 411 C
// ---------------------------------------------------------------------------

it('[E] marquerRecu Virement + IBAN connu → T2 avec 512X D / 411 C', function () {
    // Règlement avec mode Virement
    creerParticipantEtReglement($this, ModePaiement::Virement, 200.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $t1 = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->firstOrFail();
    // Simuler que le Virement est lié au même CompteBancaire (avec IBAN connu → Compte 512X)
    $t1->update(['mode_paiement' => ModePaiement::Virement->value, 'compte_id' => $this->compteBancaire->id]);
    $t1->refresh();

    $this->service->marquerRecu($t1);

    $t2 = Transaction::where('id', '!=', $t1->id)->first();
    expect($t2)->not->toBeNull();

    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->get();
    expect($lignesT2)->toHaveCount(2);

    // Ligne portage = 512X D (IBAN résolu)
    $lignePortage = $lignesT2->firstWhere('compte_id', $this->compte512X->id);
    expect($lignePortage)->not->toBeNull();
    expect((float) $lignePortage->debit)->toBe(200.0);
    expect($lignePortage->tiers_id)->toBeNull();

    // 411 C tiers
    $compte411 = compteSysteme('411');
    $ligne411T2 = $lignesT2->firstWhere('compte_id', $compte411->id);
    expect($ligne411T2)->not->toBeNull();
    expect((float) $ligne411T2->credit)->toBe(200.0);
});

// ---------------------------------------------------------------------------
// Scénario F : tests de non-régression ReglementTableTest (legacy)
// — comptabiliserSeance crée toujours 1 Tx par règlement sans transaction
// ---------------------------------------------------------------------------

it('[F] non-régression : comptabiliserSeance crée 1 Tx legacy par règlement (statut EnAttente)', function () {
    $r1 = creerParticipantEtReglement($this, ModePaiement::Cheque, 30.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    // 1 Transaction créée en base, liée au règlement
    $tx = Transaction::where('reglement_id', $r1->id)->first();
    expect($tx)->not->toBeNull();
    expect($tx->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect((float) $tx->montant_total)->toBe(30.0);
    expect((int) $tx->compte_id)->toBe((int) $this->compteBancaire->id);
});

// ---------------------------------------------------------------------------
// Scénario G : garde isLockedByRapprochement sur marquerRecu
// ---------------------------------------------------------------------------

it('[G] marquerRecu skip si Tx déjà pointée (isLockedByRapprochement = statut Pointe → pas EnAttente)', function () {
    $r1 = creerParticipantEtReglement($this, ModePaiement::Cheque, 50.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $t1 = Transaction::where('reglement_id', $r1->id)->firstOrFail();

    // Simuler verrouillage par rapprochement : statut_reglement = Pointe (isLockedByRapprochement == true)
    // La garde dans marquerRecu vérifie !EnAttente en premier → skip avant isLockedByRapprochement.
    $t1->update(['statut_reglement' => StatutReglement::Pointe->value]);

    $this->service->marquerRecu($t1->fresh());

    // statut_reglement reste Pointe (non modifié — premier guard != EnAttente)
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::Pointe);

    // Pas de T2 créée
    expect(Transaction::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scénario H : guard multi-tenant — comptabiliserSeance ignore les Reglement cross-tenant
// ---------------------------------------------------------------------------

it('[H] comptabiliserSeance ignore les Reglement cross-tenant (même seance_id par corruption)', function () {
    // Association 2 distincte — toutes les FK créées via raw insert pour éviter
    // les contraintes NOT NULL du factory (sous_categorie_id sur type_operations).
    $asso2 = Association::factory()->create();

    $operation2Id = DB::table('operations')->insertGetId([
        'association_id' => $asso2->id,
        'type_operation_id' => null,
        'nom' => 'Op asso2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Participant cross-tenant (asso2) rattaché à l'opération2
    $tiersCross = Tiers::factory()->create(['association_id' => $asso2->id]);
    $participantCross = DB::table('participants')->insertGetId([
        'association_id' => $asso2->id,
        'tiers_id' => $tiersCross->id,
        'operation_id' => $operation2Id,
        'date_inscription' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Règlement cross-tenant : participant asso2 mais seance_id = seance asso1 (corruption simulée)
    DB::table('reglements')->insert([
        'participant_id' => $participantCross,
        'seance_id' => (int) $this->seance->id, // même seance_id que asso1 !
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 999.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Revenir au contexte tenant asso1
    TenantContext::boot($this->association);

    // Règlement légitime asso1
    creerParticipantEtReglement($this, ModePaiement::Cheque, 50.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    // Seul le règlement asso1 (50.00) doit avoir généré une Transaction
    $txs = Transaction::all();
    expect($txs)->toHaveCount(1);
    expect((float) $txs->first()->montant_total)->toBe(50.0);

    // Le règlement cross-tenant (999.00) n'a pas généré de Transaction
    expect(Transaction::where('montant_total', 999.00)->count())->toBe(0);
});
