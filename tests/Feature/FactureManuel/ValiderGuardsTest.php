<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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
    $this->service = app(FactureService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Crée une facture brouillon vierge pour les tests.
 */
function creerFactureBrouillon(FactureService $service, int $tiersId): Facture
{
    return $service->creerManuelleVierge($tiersId);
}

/**
 * Ajoute une ligne MontantManuel à la facture (peut avoir sous_categorie_id null ou fourni).
 */
function ajouterLigneMontantManuel(
    Facture $facture,
    int $sousCategId,
    bool $avecSousCat = true,
): FactureLigne {
    return FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Prestation test',
        'prix_unitaire' => 100.00,
        'quantite' => 1.000,
        'montant' => 100.00,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $avecSousCat ? $sousCategId : null,
        'operation_id' => null,
        'seance' => null,
        'ordre' => 1,
    ]);
}

// ─── Guard 1 : mode_paiement_prevu requis quand ≥ 1 MontantManuel ────────────

describe('Guard mode_paiement_prevu requis sur facture MontantManuel', function () {

    it('lève une exception si mode_paiement_prevu est null et la facture porte une ligne MontantManuel', function () {
        $facture = creerFactureBrouillon($this->service, $this->tiers->id);

        // Ajouter une ligne MontantManuel avec sous_cat (donc guard sous_cat ne bloquera pas)
        ajouterLigneMontantManuel($facture, $this->sousCategorie->id, avecSousCat: true);

        // mode_paiement_prevu = null (pas de mise à jour)
        $facture->refresh();
        expect($facture->mode_paiement_prevu)->toBeNull();

        expect(fn () => $this->service->valider($facture))
            ->toThrow(
                RuntimeException::class,
                'mode de règlement prévisionnel'
            );
    });

    it('le statut reste Brouillon après l\'exception mode_paiement_prevu null', function () {
        $facture = creerFactureBrouillon($this->service, $this->tiers->id);
        ajouterLigneMontantManuel($facture, $this->sousCategorie->id, avecSousCat: true);

        try {
            $this->service->valider($facture);
        } catch (RuntimeException) {
            // attendu
        }

        $facture->refresh();
        expect($facture->statut)->toBe(StatutFacture::Brouillon)
            ->and($facture->numero)->toBeNull();
    });
});

// ─── Guard 2 : sous_categorie_id requise sur chaque MontantManuel ─────────────

describe('Guard sous_categorie_id requise sur chaque ligne MontantManuel', function () {

    it('lève une exception si une ligne MontantManuel n\'a pas de sous_categorie_id (mode_prevu fourni)', function () {
        $facture = creerFactureBrouillon($this->service, $this->tiers->id);

        // Ajouter une ligne MontantManuel SANS sous_cat
        ajouterLigneMontantManuel($facture, $this->sousCategorie->id, avecSousCat: false);

        // Fournir mode_paiement_prevu
        $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
        $facture->refresh();

        expect(fn () => $this->service->valider($facture))
            ->toThrow(
                RuntimeException::class,
                'sous-catégorie'
            );
    });

    it('le statut reste Brouillon après l\'exception sous_categorie_id null', function () {
        $facture = creerFactureBrouillon($this->service, $this->tiers->id);
        ajouterLigneMontantManuel($facture, $this->sousCategorie->id, avecSousCat: false);
        $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
        $facture->refresh();

        try {
            $this->service->valider($facture);
        } catch (RuntimeException) {
            // attendu
        }

        $facture->refresh();
        expect($facture->statut)->toBe(StatutFacture::Brouillon)
            ->and($facture->numero)->toBeNull();
    });

    it('lève une exception si 2 lignes MontantManuel dont 1 sans sous_categorie_id (mode_prevu fourni)', function () {
        $facture = creerFactureBrouillon($this->service, $this->tiers->id);

        // Ligne 1 : avec sous_cat
        FactureLigne::create([
            'facture_id' => $facture->id,
            'type' => TypeLigneFacture::MontantManuel,
            'libelle' => 'Ligne avec sous-catégorie',
            'prix_unitaire' => 200.00,
            'quantite' => 1.000,
            'montant' => 200.00,
            'transaction_ligne_id' => null,
            'sous_categorie_id' => $this->sousCategorie->id,
            'operation_id' => null,
            'seance' => null,
            'ordre' => 1,
        ]);

        // Ligne 2 : sans sous_cat
        FactureLigne::create([
            'facture_id' => $facture->id,
            'type' => TypeLigneFacture::MontantManuel,
            'libelle' => 'Ligne sans sous-catégorie',
            'prix_unitaire' => 300.00,
            'quantite' => 1.000,
            'montant' => 300.00,
            'transaction_ligne_id' => null,
            'sous_categorie_id' => null,
            'operation_id' => null,
            'seance' => null,
            'ordre' => 2,
        ]);

        $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value, 'montant_total' => 500.00]);
        $facture->refresh();

        expect(fn () => $this->service->valider($facture))
            ->toThrow(RuntimeException::class, 'sous-catégorie');
    });
});

// ─── Régression : factures classiques (Montant ref / Texte) — guards NON déclenchés ─

describe('Régression : factures classiques inchangées', function () {

    it('facture avec uniquement des lignes Montant ref et mode_paiement_prevu null → validation réussit', function () {
        // Créer une facture via le service classique (creer), puis ajouter des transactions
        // Pour ce test on construit manuellement une facture avec lignes Montant (comme le service le ferait)
        $facture = $this->service->creer($this->tiers->id);

        // Ajouter une transaction + ligne Montant de manière directe (comme ajouterTransactions le fait)
        $transaction = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'montant_total' => 500.00,
        ]);

        $this->service->ajouterTransactions($facture, [$transaction->id]);
        $facture->refresh();

        // mode_paiement_prevu est null sur une facture classique — guards ne doivent PAS lever
        expect($facture->mode_paiement_prevu)->toBeNull();
        expect($facture->lignes()->where('type', TypeLigneFacture::Montant)->count())->toBeGreaterThan(0);

        // La validation doit réussir sans exception
        $this->service->valider($facture);

        $facture->refresh();
        expect($facture->statut)->toBe(StatutFacture::Validee)
            ->and($facture->numero)->not->toBeNull();
    });

    it('facture avec uniquement des lignes Texte → refusée par le guard "au moins une ligne avec montant" (comportement existant inchangé)', function () {
        $facture = $this->service->creer($this->tiers->id);

        // Ajouter uniquement une ligne Texte
        $this->service->ajouterLigneTexte($facture, 'Mention contractuelle');
        $facture->refresh();

        // Le guard existant "La facture doit contenir au moins une ligne avec montant" s'applique
        expect(fn () => $this->service->valider($facture))
            ->toThrow(RuntimeException::class, 'au moins une ligne');
    });
});

// ─── Happy path : facture MontantManuel complète → statut passe à Validee ────

describe('Happy path : guards passent (mode_prevu + sous_cat fournis)', function () {

    it('facture brouillon avec 1 MontantManuel complet + mode_prevu fourni → statut passe à Validee, aucune exception', function () {
        $facture = creerFactureBrouillon($this->service, $this->tiers->id);

        // Ajouter une ligne MontantManuel complète
        ajouterLigneMontantManuel($facture, $this->sousCategorie->id, avecSousCat: true);

        // Fournir mode_paiement_prevu
        $facture->update([
            'mode_paiement_prevu' => ModePaiement::Virement->value,
            'montant_total' => 100.00,
        ]);
        $facture->refresh();

        // Les guards doivent passer, et la validation doit réussir
        // NOTE Step 7 : la génération de transaction sera testée dans ValiderGenereTransactionTest
        $this->service->valider($facture);

        $facture->refresh();
        expect($facture->statut)->toBe(StatutFacture::Validee)
            ->and($facture->numero)->not->toBeNull();
    });
});

// ─── Rollback integrity ───────────────────────────────────────────────────────

describe('Rollback integrity : aucune mutation si un guard échoue', function () {

    it('après exception mode_paiement_prevu null : facture rechargée depuis DB est toujours Brouillon, aucun numéro, aucune transaction créée', function () {
        $facture = creerFactureBrouillon($this->service, $this->tiers->id);
        ajouterLigneMontantManuel($facture, $this->sousCategorie->id, avecSousCat: true);

        $countTransactionsAvant = Transaction::count();

        try {
            $this->service->valider($facture);
        } catch (RuntimeException) {
            // attendu
        }

        // Recharger depuis DB
        $factureDb = Facture::find($facture->id);

        expect($factureDb->statut)->toBe(StatutFacture::Brouillon)
            ->and($factureDb->numero)->toBeNull();

        // Aucune transaction créée
        expect(Transaction::count())->toBe($countTransactionsAvant);
    });
});
