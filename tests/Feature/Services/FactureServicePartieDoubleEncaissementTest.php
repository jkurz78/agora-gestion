<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    // Tiers (spécifique FactureService encaissement)
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $this->service = app(FactureService::class);
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

// ---------------------------------------------------------------------------
// Scénario A : T2 créée avec Chèque — 5112 D / 411 C tiers + auto-lettrage
// ---------------------------------------------------------------------------

it('[A] marquerReglementRecu Cheque → T2 créée (5112 D / 411 C tiers), 411 auto-lettrée', function () {
    [$facture, $t1] = creerFactureEtT1($this, ModePaiement::Cheque);

    // Précondition : 1 transaction (T1) attachée, ligne 411 non lettrée
    expect($facture->transactions()->count())->toBe(1);
    $compte411 = compteSysteme('411');
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

    $compte5112 = compteSysteme('5112');

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

    $compte411 = compteSysteme('411');

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
    $compte411 = compteSysteme('411');
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
    // Stratégie : créer une facture avec T1a (générée par valider()) + T1b (factice, EnAttente).
    // montant_total = 400 pour que isAcquittee() reste false après le 1er encaissement de T1a (200).
    // Ainsi le 2ème appel marquerReglementRecu([$t1a->id]) atteint encaisserPartieDouble()
    // et déclenche LettrageDejaPresentException (ligne 411 T1a déjà lettrée au 1er appel).

    // T1a : créée normalement via valider() — porte une ligne 411 PD
    [$facture, $t1a] = creerFactureEtT1($this, ModePaiement::Cheque);
    // montant_total initial = 200 (1 ligne MontantManuel de 200)

    // T1b : transaction en attente factice représentant un 2ème paiement attendu
    $t1b = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 200.00,
        'mode_paiement' => ModePaiement::Cheque->value,
    ]);
    $facture->transactions()->attach($t1b->id);

    // Ajuster montant_total à 400 pour refléter les 2 transactions attendues.
    // isAcquittee() = montantRegle() >= montant_total.
    // Après 1er appel (T1a → Recu) : montantRegle = 200 < 400 → pas acquittée → 2ème appel peut passer.
    $facture->update(['montant_total' => 400.00]);
    $facture->refresh();

    // 1er appel : OK — T1a lettrée, T2a créée
    $this->service->marquerReglementRecu($facture, [$t1a->id]);
    $facture->refresh();

    // T2a créée et attachée (T1a + T1b + T2a = 3 transactions)
    expect($facture->transactions()->count())->toBe(3);

    // Ligne 411 de T1a bien lettrée
    $compte411 = compteSysteme('411');
    $ligne411T1a = TransactionLigne::where('transaction_id', $t1a->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    $ligne411T1a->refresh();
    expect($ligne411T1a->lettrage_code)->not->toBeNull('ligne 411 T1a doit être lettrée après 1er encaissement');

    // T1b reste non lettrée (aucune ligne 411 — transaction factice sans PD)
    $lignes411T1b = TransactionLigne::where('transaction_id', $t1b->id)
        ->where('compte_id', $compte411->id)
        ->get();
    expect($lignes411T1b)->toBeEmpty('T1b ne doit porter aucune ligne 411');

    $countAvant2eAppel = $facture->transactions()->count(); // = 3

    // 2ème appel avec T1a (déjà lettrée) → LettrageDejaPresentException + rollback
    // isAcquittee() : montantRegle() = 200 (T1a Recu) < 400 → false → passe la garde
    $facture->refresh();
    expect(fn () => $this->service->marquerReglementRecu($facture, [$t1a->id]))
        ->toThrow(LettrageDejaPresentException::class);

    // Rollback complet : pas de nouvelle transaction créée
    $facture->refresh();
    expect($facture->transactions()->count())->toBe($countAvant2eAppel);

    // T1a reste Recu (statut non régressé par le rollback)
    $t1a->refresh();
    expect($t1a->statut_reglement->value)->toBe(StatutReglement::Recu->value);

    // T1b reste EnAttente (intacte, rollback n'affecte que T1a)
    $t1b->refresh();
    expect($t1b->statut_reglement->value)->toBe(StatutReglement::EnAttente->value);
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
            return str_contains($message, 'FactureService') && str_contains($message, 'compte_id null');
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
