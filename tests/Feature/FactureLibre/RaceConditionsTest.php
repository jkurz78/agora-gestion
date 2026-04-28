<?php

declare(strict_types=1);

/**
 * Tests de consolidation — races transformation devis et validation facture.
 *
 * Les protections réelles (lockForUpdate + guards statut) sont exercées par
 *   - ValiderGenereTransactionTest  (Tests 4 : race double validation)
 *   - TransformerDevisEnFactureTest (Tests 4 : race double transformation)
 *
 * Ce fichier consolide les assertions clés depuis le point de vue "count en base"
 * et documente explicitement que le pattern projet (2 DB::transaction séquentielles
 * dans le même process, lockForUpdate sérialise les écritures) est en place.
 */

use App\Enums\ModePaiement;
use App\Enums\StatutDevis;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneDevis;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DevisService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Setup ──────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);

    $this->tiers = Tiers::factory()->create();
    $this->sousCategorie = SousCategorie::factory()->create();
    $this->factureService = app(FactureService::class);
    $this->devisService = app(DevisService::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Crée un devis Accepté avec 1 ligne Montant.
 */
function creerDevisAcceptePourRace(Tiers $tiers, SousCategorie $sousCategorie): Devis
{
    $devis = new Devis([
        'tiers_id' => $tiers->id,
        'statut' => StatutDevis::Accepte,
        'montant_total' => 1000.00,
        'numero' => 'D-2026-RACE-001',
        'date_emission' => now()->toDateString(),
        'date_validite' => now()->addDays(30)->toDateString(),
        'saisi_par_user_id' => auth()->id(),
    ]);
    $devis->exercice = 2026;
    $devis->save();

    DevisLigne::create([
        'devis_id' => $devis->id,
        'ordre' => 1,
        'type' => TypeLigneDevis::Montant,
        'libelle' => 'Prestation race',
        'prix_unitaire' => 1000.00,
        'quantite' => 1.0,
        'montant' => 1000.00,
        'sous_categorie_id' => $sousCategorie->id,
    ]);

    return $devis;
}

/**
 * Crée une facture brouillon libre avec 1 ligne MontantLibre prête à valider.
 */
function creerFactureLibrePourRace(FactureService $service, Tiers $tiers, SousCategorie $sousCategorie): Facture
{
    $facture = $service->creerLibreVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantLibre,
        'libelle' => 'Prestation race',
        'prix_unitaire' => 800.0,
        'quantite' => 1.0,
        'montant' => 800.0,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'operation_id' => null,
        'seance' => null,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => 800.0]);

    return $facture->fresh();
}

// ─── Race 1 : Transformation devis ──────────────────────────────────────────

describe('Race transformation devis : 2 transformations séquentielles → 1 seule facture', function (): void {

    /**
     * Stratégie projet (cf S6 multi-tenancy) :
     * Deux DB::transaction séquentielles dans le même process.
     * La première réussit. La seconde charge le devis depuis la DB (lockForUpdate visible),
     * voit aDejaUneFacture() = true, et lève une RuntimeException.
     * Assertion finale : Facture::where('devis_id', ..)->count() === 1.
     */
    it('la seconde transformation lève une exception et n\'insère pas de doublon', function (): void {
        $devis = creerDevisAcceptePourRace($this->tiers, $this->sousCategorie);

        // Worker 1 : réussit
        $this->devisService->transformerEnFacture($devis);

        // Worker 2 : simule le rechargement depuis DB après lockForUpdate
        $devisDuplique = Devis::withoutGlobalScopes()->find($devis->id);

        expect(fn () => $this->devisService->transformerEnFacture($devisDuplique))
            ->toThrow(RuntimeException::class);

        // Assertion clé : exactement 1 facture, pas 2
        expect(Facture::withoutGlobalScopes()->where('devis_id', $devis->id)->count())->toBe(1);
    });

    it('l\'unique facture créée est correctement liée au devis', function (): void {
        $devis = creerDevisAcceptePourRace($this->tiers, $this->sousCategorie);

        $factureCreee = $this->devisService->transformerEnFacture($devis);

        // Simule le 2e worker silencieusement
        try {
            $this->devisService->transformerEnFacture(Devis::withoutGlobalScopes()->find($devis->id));
        } catch (RuntimeException) {
        }

        $factureEnBase = Facture::where('devis_id', $devis->id)->firstOrFail();

        expect((int) $factureEnBase->id)->toBe((int) $factureCreee->id)
            ->and($factureEnBase->statut)->toBe(StatutFacture::Brouillon)
            ->and((int) $factureEnBase->tiers_id)->toBe((int) $this->tiers->id);
    });
});

// ─── Race 2 : Validation facture ─────────────────────────────────────────────

describe('Race validation facture : 2 validations séquentielles → 1 seule Transaction créée', function (): void {

    /**
     * Stratégie projet (cf S6 multi-tenancy) :
     * Le premier valider() réussit (statut → Validee).
     * Le second valider() charge la même facture fraîche depuis la DB.
     * Le guard "statut doit être brouillon" lève une RuntimeException.
     * Assertion finale : Transaction::where('libelle', 'Facture {numero}')->count() === 1.
     */
    it('le second appel à valider lève une exception et ne crée pas de doublon de Transaction', function (): void {
        $facture = creerFactureLibrePourRace($this->factureService, $this->tiers, $this->sousCategorie);

        $countAvant = Transaction::count();

        // Worker 1 : réussit
        $this->factureService->valider($facture);

        // Worker 2 : recharge la facture depuis la DB (maintenant Validee)
        $factureDupliquee = Facture::find($facture->id);

        expect(fn () => $this->factureService->valider($factureDupliquee))
            ->toThrow(RuntimeException::class);

        // Exactement 1 Transaction créée, pas 2
        expect(Transaction::count())->toBe($countAvant + 1);
    });

    it('l\'assertion count sur le libellé de la transaction confirme l\'unicité', function (): void {
        $facture = creerFactureLibrePourRace($this->factureService, $this->tiers, $this->sousCategorie);

        // Worker 1
        $this->factureService->valider($facture);
        $facture->refresh();

        // Worker 2 silencieux
        try {
            $this->factureService->valider(Facture::find($facture->id));
        } catch (RuntimeException) {
        }

        // Une seule transaction avec ce libellé en base
        expect(
            Transaction::where('libelle', "Facture {$facture->numero}")->count()
        )->toBe(1);
    });

    it('après la race, la facture reste au statut Validee (pas de corruption)', function (): void {
        $facture = creerFactureLibrePourRace($this->factureService, $this->tiers, $this->sousCategorie);

        $this->factureService->valider($facture);

        // Worker 2 silencieux
        try {
            $this->factureService->valider(Facture::find($facture->id));
        } catch (RuntimeException) {
        }

        expect($facture->fresh()->statut)->toBe(StatutFacture::Validee);
    });
});
