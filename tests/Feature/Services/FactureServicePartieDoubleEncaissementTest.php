<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

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

    // Catégorie recette
    $categorieRecette = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Prestations',
    ]);

    // Sous-catégorie 706
    $this->sc706 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieRecette->id,
        'nom' => 'Cotisations',
        'code_cerfa' => '706',
    ]);

    // Compte 706 correspondant
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

    // Tiers
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    // CompteBancaire avec IBAN connu
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
    ]);

    // Compte 512X correspondant (avec le même IBAN — pattern Step 21)
    $this->compte512X = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '5121',
        'intitule' => 'Banque principale',
        'classe' => 5,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'iban' => $this->iban,
    ]);

    $this->service = app(FactureService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée une facture brouillon avec 1 ligne MontantManuel, la valide → retourne
 * [facture->fresh(), t1 (Transaction créée par valider())].
 */
function creerFactureEtT1(
    object $ctx,
    ModePaiement $modePaiement = ModePaiement::Cheque,
    ?int $compteBancaireId = null,
): array {
    $facture = Facture::create([
        'association_id' => $ctx->association->id,
        'date' => '2025-11-15',
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $ctx->tiers->id,
        'saisi_par' => $ctx->user->id,
        'exercice' => 2025,
        'montant_total' => 0,
        'mode_paiement_prevu' => $modePaiement->value,
        'compte_bancaire_id' => $compteBancaireId,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel->value,
        'sous_categorie_id' => $ctx->sc706->id,
        'libelle' => 'Cotisation annuelle',
        'montant' => 200.00,
        'ordre' => 1,
    ]);

    $ctx->service->valider($facture);
    $facture = $facture->fresh();

    $t1 = $facture->transactions()->first();

    return [$facture, $t1];
}

/** Raccourci compte 411 tenant courant. */
function compte411Enc(): Compte
{
    return Compte::where('numero_pcg', '411')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
}

/** Raccourci compte 5112 tenant courant. */
function compte5112Enc(): Compte
{
    return Compte::where('numero_pcg', '5112')
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
}

// ---------------------------------------------------------------------------
// Scénario A : T2 créée avec Chèque — 5112 D / 411 C tiers + auto-lettrage
// ---------------------------------------------------------------------------

it('[A] marquerReglementRecu Cheque → T2 créée (5112 D / 411 C tiers), 411 auto-lettrée', function () {
    [$facture, $t1] = creerFactureEtT1($this, ModePaiement::Cheque);

    // Précondition : 1 transaction (T1) attachée, ligne 411 non lettrée
    expect($facture->transactions()->count())->toBe(1);
    $compte411 = compte411Enc();
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBeNull();

    // Action
    $this->service->marquerReglementRecu($facture, [$t1->id]);
    $facture->refresh();
    $t1->refresh();

    // statut_reglement = Recu sur T1
    expect($t1->statut_reglement->value)->toBe(StatutReglement::Recu->value);

    // La facture a maintenant 2 transactions : T1 + T2
    expect($facture->transactions()->count())->toBe(2);

    // T2 = la Transaction ≠ T1
    $t2 = $facture->transactions()->where('id', '!=', $t1->id)->first();
    expect($t2)->not->toBeNull();

    // T2 porte 2 lignes PD
    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->get();
    expect($lignesT2)->toHaveCount(2);

    $compte5112 = compte5112Enc();

    // Ligne portage : 5112 D (pour Chèque reçu)
    $lignePortage = $lignesT2->firstWhere('compte_id', $compte5112->id);
    expect($lignePortage)->not->toBeNull();
    expect((float) $lignePortage->debit)->toBe(200.0);
    expect((float) $lignePortage->credit)->toBe(0.0);
    expect($lignePortage->tiers_id)->toBeNull(); // FEC : pas de tiers sur 5x

    // Ligne 411 C tiers
    $ligne411T2 = $lignesT2->firstWhere('compte_id', $compte411->id);
    expect($ligne411T2)->not->toBeNull();
    expect((float) $ligne411T2->debit)->toBe(0.0);
    expect((float) $ligne411T2->credit)->toBe(200.0);
    expect((int) $ligne411T2->tiers_id)->toBe((int) $this->tiers->id);

    // Auto-lettrage : T1.ligne411 et T2.ligne411 partagent le même lettrage_code
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    expect($ligne411T1->lettrage_code)->not->toBeNull();
    expect($ligne411T2->lettrage_code)->not->toBeNull();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);
});

// ---------------------------------------------------------------------------
// Scénario B : T2 créée avec Virement → 512X D / 411 C (résolution IBAN)
// ---------------------------------------------------------------------------

it('[B] marquerReglementRecu Virement + IBAN connu → T2 créée (512X D / 411 C), auto-lettrage 411', function () {
    [$facture, $t1] = creerFactureEtT1(
        $this,
        ModePaiement::Virement,
        $this->compteBancaire->id, // compte_bancaire_id → IBAN → Compte 512X
    );

    $this->service->marquerReglementRecu($facture, [$t1->id]);
    $facture->refresh();

    expect($facture->transactions()->count())->toBe(2);

    $t2 = $facture->transactions()->where('id', '!=', $t1->id)->first();
    expect($t2)->not->toBeNull();

    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->get();
    expect($lignesT2)->toHaveCount(2);

    $compte411 = compte411Enc();

    // Ligne portage : 512X D (résolution IBAN)
    $lignePortage = $lignesT2->firstWhere('compte_id', $this->compte512X->id);
    expect($lignePortage)->not->toBeNull();
    expect((float) $lignePortage->debit)->toBe(200.0);
    expect((float) $lignePortage->credit)->toBe(0.0);
    expect($lignePortage->tiers_id)->toBeNull();

    // Ligne 411 C tiers
    $ligne411T2 = $lignesT2->firstWhere('compte_id', $compte411->id);
    expect($ligne411T2)->not->toBeNull();
    expect((float) $ligne411T2->credit)->toBe(200.0);
    expect((int) $ligne411T2->tiers_id)->toBe((int) $this->tiers->id);

    // Auto-lettrage
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    expect($ligne411T1->lettrage_code)->not->toBeNull();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);
});

// ---------------------------------------------------------------------------
// Scénario C : Solde ouvert 411 du tiers = 0 après encaissement
// ---------------------------------------------------------------------------

it('[C] solde ouvert 411 du tiers = 0 après encaissement (lettrage complet)', function () {
    [$facture, $t1] = creerFactureEtT1($this, ModePaiement::Cheque);

    // Avant encaissement : solde ouvert = 200
    $compte411 = compte411Enc();
    $lignes411AvantEnc = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->get();
    $soldeAvant = $lignes411AvantEnc->sum(fn (TransactionLigne $l) => (float) $l->debit - (float) $l->credit);
    expect($soldeAvant)->toBe(200.0);

    // Encaissement
    $this->service->marquerReglementRecu($facture, [$t1->id]);

    // Après encaissement : lignes non lettrées = 0 (les deux lignes 411 sont maintenant lettrées)
    $lignes411ApresEnc = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->get();

    $soldeOuvert = $lignes411ApresEnc->sum(fn (TransactionLigne $l) => (float) $l->debit - (float) $l->credit);
    // sum() retourne 0 (integer) sur une collection vide → utiliser toEqual (type-coercive)
    expect($soldeOuvert)->toEqual(0.0);
});

// ---------------------------------------------------------------------------
// Scénario D : Double encaissement → LettrageDejaPresentException au 2ème appel
// ---------------------------------------------------------------------------

it('[D] double encaissement → LettrageDejaPresentException, pas de T3 créée', function () {
    [$facture, $t1] = creerFactureEtT1($this, ModePaiement::Cheque);

    // 1er appel : OK
    $this->service->marquerReglementRecu($facture, [$t1->id]);
    $facture->refresh();
    expect($facture->transactions()->count())->toBe(2);

    // 2ème appel : throw LettrageDejaPresentException (ligne 411 T1 déjà lettrée)
    // On doit d'abord remettre la facture en état "pas acquittée" pour passer la garde isAcquittee.
    // Mais la facture EST acquittée après le 1er appel. Il faut créer une 2ème transaction attachée
    // non-Recu pour que isAcquittee() retourne false, puis tenter de re-encaisser T1.
    //
    // Approche alternative : tester directement la garde LettrageDejaPresentException
    // en appelant EcritureGenerator::pourEncaissementCreance directement — mais c'est un test unitaire.
    //
    // Approche réaliste : on ne bypass pas isAcquittee(). On teste le rollback en créant une
    // 2ème transaction non-Recu pour que la facture ne soit pas acquittée, puis on tente
    // marquerReglementRecu avec [$t1->id] → ligne 411 T1 déjà lettrée → throw.

    // Crée T3 (non liée au flux PD, juste pour rendre la facture "non acquittée")
    $txExtra = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 50.00,
    ]);
    $facture->transactions()->attach($txExtra->id);
    // Facture n'est plus acquittée car montant_total > montantRegle()
    // Mais montant_total = 200 et montantRegle() = 200 (t1 statut_reglement=Recu)…
    // En fait isAcquittee() est basé sur montantRegle() vs montant_total.
    // On va plutôt vérifier que la 2ème tentative sur t1 (déjà lettré) throw correctement
    // en créant un nouveau contexte simulant une re-ouverture.

    // Vérifier simplement que la ligne 411 de T1 est bien lettrée → guard dans EcritureGenerator
    $compte411 = compte411Enc();
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    $ligne411T1->refresh();
    expect($ligne411T1->lettrage_code)->not->toBeNull('ligne 411 T1 doit être lettrée après 1er encaissement');

    // La T2 créée par le 1er encaissement ne doit pas être recréée
    $countT2Avant = $facture->transactions()->count(); // = 3 avec txExtra

    // On attend LettrageDejaPresentException en tentant de re-encaisser T1
    // (on bypasse la garde isAcquittee() car on a ajouté txExtra, montantRegle() reste 200
    // qui == montant_total 200 → isAcquittee() reste true → la garde bloque).
    // Ajustons : on augmente montant_total pour que isAcquittee() retourne false.
    $facture->update(['montant_total' => 250.00]);
    $facture->refresh();

    expect(fn () => $this->service->marquerReglementRecu($facture, [$t1->id]))
        ->toThrow(LettrageDejaPresentException::class);

    // Rollback → pas de nouvelle T créée (le count reste identique)
    $facture->refresh();
    expect($facture->transactions()->count())->toBe($countT2Avant);
    // T1 statut_reglement reste Recu (no-op car il était déjà Recu avant le 2ème appel)
    $t1->refresh();
    expect($t1->statut_reglement->value)->toBe(StatutReglement::Recu->value);
});

// ---------------------------------------------------------------------------
// Scénario E : Mode Virement + compte_id null → skip PD, statut_reglement = Recu
// ---------------------------------------------------------------------------

it('[E] Virement + compte_id null → skip PD silencieux, statut_reglement passe à Recu, Log::warning', function () {
    Log::spy();

    // T1 créée sans compte_bancaire_id (compte_id null)
    [$facture, $t1] = creerFactureEtT1($this, ModePaiement::Virement, null);

    // Vérification que T1 a bien compte_id null
    expect($t1->compte_id)->toBeNull();

    $this->service->marquerReglementRecu($facture, [$t1->id]);

    $t1->refresh();
    // statut_reglement passe à Recu malgré le skip PD
    expect($t1->statut_reglement->value)->toBe(StatutReglement::Recu->value);

    // Aucune T2 créée (skip PD)
    $facture->refresh();
    expect($facture->transactions()->count())->toBe(1);

    // Log::warning émis (comportement documenté du skip)
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return str_contains($message, 'Step 24') && str_contains($message, 'compte_id null');
        });
});

// ---------------------------------------------------------------------------
// Scénario F : Tests existants FactureServiceReglementRecuTest restent verts
// (toggle statut_reglement intact)
// ---------------------------------------------------------------------------

it('[F] toggle statut_reglement intact — Transaction sans lignes PD passe à Recu', function () {
    // Facture validée créée directement (pas de lignes MontantManuel → pas de T1 générée par valider())
    $facture = Facture::create([
        'association_id' => $this->association->id,
        'date' => '2025-11-15',
        'statut' => StatutFacture::Validee,
        'tiers_id' => $this->tiers->id,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
        'montant_total' => 80.00,
        'mode_paiement_prevu' => ModePaiement::Cheque->value,
    ]);

    // Transaction sans lignes PD (pas de ligne 411 → skip EcritureGenerator)
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 80.00,
        'mode_paiement' => ModePaiement::Cheque->value,
    ]);
    $facture->transactions()->attach($tx->id);

    $this->service->marquerReglementRecu($facture, [$tx->id]);

    $tx->refresh();
    expect($tx->statut_reglement->value)->toBe(StatutReglement::Recu->value);

    // Aucune T2 créée (pas de ligne 411 dans T1 → skip gracieux sans exception)
    $facture->refresh();
    expect($facture->transactions()->count())->toBe(1);
});
