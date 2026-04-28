<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─── Setup ──────────────────────────────────────────────────────────────────

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

// ─── Helper : facture brouillon avec N lignes MontantLibre ──────────────────

/**
 * Crée une facture brouillon libre prête à être validée.
 */
function creerFactureLibre(
    FactureService $service,
    int $tiersId,
    ModePaiement $mode = ModePaiement::Virement,
): Facture {
    $facture = $service->creerLibreVierge($tiersId);
    $facture->update(['mode_paiement_prevu' => $mode->value]);

    return $facture->fresh();
}

/**
 * Ajoute une ligne MontantLibre directement en DB (sans passer par le service).
 */
function ajouterLigneLibre(
    Facture $facture,
    SousCategorie $sousCategorie,
    float $prixUnitaire,
    float $quantite = 1.0,
    string $libelle = 'Prestation test',
    ?int $operationId = null,
    ?int $seance = null,
): FactureLigne {
    $montant = round($prixUnitaire * $quantite, 2);

    return FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantLibre,
        'libelle' => $libelle,
        'prix_unitaire' => $prixUnitaire,
        'quantite' => $quantite,
        'montant' => $montant,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'operation_id' => $operationId,
        'seance' => $seance,
        'ordre' => FactureLigne::where('facture_id', $facture->id)->count() + 1,
    ]);
}

// ─── Test 1 : Happy path principal ──────────────────────────────────────────

describe('Happy path : 2 lignes MontantLibre → 1 Transaction recette + 2 TransactionLignes', function () {

    it('crée 1 Transaction recette avec le bon montant_total et statut', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 1200.0, 1.0, 'Mission');
        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 1400.0]);

        $countAvant = Transaction::count();

        $this->service->valider($facture);

        expect(Transaction::count())->toBe($countAvant + 1);

        $transaction = Transaction::latest('id')->first();

        expect($transaction)->not->toBeNull()
            ->and($transaction->type)->toBe(TypeTransaction::Recette)
            ->and((int) $transaction->tiers_id)->toBe((int) $this->tiers->id)
            ->and((float) $transaction->montant_total)->toBe(1400.0)
            ->and($transaction->statut_reglement)->toBe(StatutReglement::EnAttente)
            ->and($transaction->mode_paiement)->toBe(ModePaiement::Virement)
            ->and((int) $transaction->association_id)->toBe((int) $this->association->id);
    });

    it('le libellé de la Transaction est "Facture {numero attribué}"', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 1200.0, 1.0, 'Mission');
        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 1400.0]);

        $this->service->valider($facture);

        $facture->refresh();
        $transaction = Transaction::latest('id')->first();

        expect($transaction->libelle)->toBe("Facture {$facture->numero}");
        expect($facture->numero)->not->toBeNull();
    });

    it('crée 2 TransactionLignes avec les bons montants et sous_cat', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 1200.0, 1.0, 'Mission');
        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 1400.0]);

        $this->service->valider($facture);

        $transaction = Transaction::latest('id')->first();

        expect($transaction->lignes)->toHaveCount(2);

        $montants = $transaction->lignes->pluck('montant')->map(fn ($m) => (float) $m)->sort()->values()->all();
        expect($montants)->toBe([200.0, 1200.0]);

        foreach ($transaction->lignes as $ligne) {
            expect((int) $ligne->sous_categorie_id)->toBe((int) $this->sousCategorie->id);
        }
    });

    it('les notes des TransactionLignes reprennent le libellé des FactureLignes', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 1200.0, 1.0, 'Mission conseil');
        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais annexes');
        $facture->update(['montant_total' => 1400.0]);

        $this->service->valider($facture);

        $transaction = Transaction::latest('id')->first();

        $notes = $transaction->lignes->pluck('notes')->sort()->values()->all();
        expect($notes)->toContain('Mission conseil')
            ->and($notes)->toContain('Frais annexes');
    });

    it('chaque FactureLigne::MontantLibre.transaction_ligne_id est setté sur la TransactionLigne correspondante', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        $fl1 = ajouterLigneLibre($facture, $this->sousCategorie, 1200.0, 1.0, 'Mission');
        $fl2 = ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 1400.0]);

        $this->service->valider($facture);

        $fl1->refresh();
        $fl2->refresh();

        expect($fl1->transaction_ligne_id)->not->toBeNull()
            ->and($fl2->transaction_ligne_id)->not->toBeNull()
            ->and($fl1->transaction_ligne_id)->not->toBe($fl2->transaction_ligne_id);

        // Vérifier que les IDs pointent vers des TransactionLignes existantes
        expect(TransactionLigne::find($fl1->transaction_ligne_id))->not->toBeNull()
            ->and(TransactionLigne::find($fl2->transaction_ligne_id))->not->toBeNull();
    });

    it('le pivot facture_transaction contient la nouvelle Transaction', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 1200.0, 1.0, 'Mission');
        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 1400.0]);

        $this->service->valider($facture);

        $facture->refresh();
        $transaction = Transaction::latest('id')->first();

        expect($facture->transactions()->where('transactions.id', $transaction->id)->exists())->toBeTrue();
    });

    it('la Transaction générée est verrouillée par la facture validée (isLockedByFacture)', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 1200.0, 1.0, 'Mission');
        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 1400.0]);

        $this->service->valider($facture);

        $transaction = Transaction::latest('id')->first();

        expect($transaction->isLockedByFacture())->toBeTrue();
    });
});

// ─── Test 2 : Mix Montant ref + MontantLibre ────────────────────────────────

describe('Mix Montant (ref) + MontantLibre → 2 transactions dans le pivot', function () {

    it('crée 1 NOUVELLE Transaction pour les lignes libres + conserve la ref dans le pivot', function () {
        // Prépare une transaction existante (T-12) liée à la facture via lignes Montant
        $transactionExistante = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'montant_total' => 500.0,
            'statut_reglement' => StatutReglement::Recu->value,
        ]);

        $facture = creerFactureLibre($this->service, $this->tiers->id);

        // Attache la transaction existante via le service
        $this->service->ajouterTransactions($facture, [$transactionExistante->id]);
        $facture->refresh();

        // Ajoute une ligne MontantLibre
        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 700.0]);

        $countAvant = Transaction::count();

        $this->service->valider($facture);

        // Une seule nouvelle Transaction créée
        expect(Transaction::count())->toBe($countAvant + 1);

        $nouvelleTransaction = Transaction::latest('id')->first();
        expect((float) $nouvelleTransaction->montant_total)->toBe(200.0);

        // Le pivot porte les deux transactions
        $facture->refresh();
        $txIds = $facture->transactions()->pluck('transactions.id')->toArray();

        expect($txIds)->toContain($transactionExistante->id)
            ->and($txIds)->toContain($nouvelleTransaction->id);
    });

    it('le total facture reste 700 après validation (Montant ref 500 + MontantLibre 200)', function () {
        $transactionExistante = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'montant_total' => 500.0,
        ]);

        $facture = creerFactureLibre($this->service, $this->tiers->id);
        $this->service->ajouterTransactions($facture, [$transactionExistante->id]);
        $facture->refresh();

        ajouterLigneLibre($facture, $this->sousCategorie, 200.0, 1.0, 'Frais');
        $facture->update(['montant_total' => 700.0]);

        $this->service->valider($facture);

        $facture->refresh();
        expect((float) $facture->montant_total)->toBe(700.0);
    });
});

// ─── Test 3 : Facture sans MontantLibre → aucune Transaction créée ───────────

describe('Facture sans MontantLibre → aucune nouvelle Transaction créée', function () {

    it('validation d\'une facture classique (Montant ref uniquement) ne crée pas de Transaction', function () {
        $transactionExistante = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'montant_total' => 300.0,
        ]);

        // Utilise la méthode creer classique (pas creerLibreVierge)
        $facture = $this->service->creer($this->tiers->id);
        $this->service->ajouterTransactions($facture, [$transactionExistante->id]);
        $facture->refresh();

        $countAvant = Transaction::count();

        $this->service->valider($facture);

        // Pas de nouvelle Transaction
        expect(Transaction::count())->toBe($countAvant);
    });

    it('validation d\'une facture libre avec lignes Montant ref + Texte uniquement ne crée pas de Transaction', function () {
        $transactionExistante = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'montant_total' => 300.0,
        ]);

        $facture = creerFactureLibre($this->service, $this->tiers->id);
        $this->service->ajouterTransactions($facture, [$transactionExistante->id]);

        // Ligne Texte uniquement (pas de MontantLibre)
        $this->service->ajouterLigneLibreTexte($facture, 'Mention contractuelle');
        $facture->refresh();

        $countAvant = Transaction::count();

        $this->service->valider($facture);

        expect(Transaction::count())->toBe($countAvant);
    });
});

// ─── Test 4 : Race — double validation de la même facture ───────────────────

describe('Race : double validation de la même facture → no-op ou exception, jamais 2 Transactions', function () {

    it('le second appel à valider sur une facture déjà validée lève une exception et ne crée pas de doublon', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 1000.0, 1.0, 'Prestation');
        $facture->update(['montant_total' => 1000.0]);

        // Premier appel : réussit
        $this->service->valider($facture);
        $countApremierValidation = Transaction::count();

        // Second appel sur la même instance (statut maintenant Validee)
        $facture->refresh();

        expect(fn () => $this->service->valider($facture))
            ->toThrow(RuntimeException::class, 'brouillon');

        // Aucune nouvelle Transaction
        expect(Transaction::count())->toBe($countApremierValidation);
    });

    it('simulation de race : 2 validations séquentielles sur une facture — 1 seule Transaction créée', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 500.0, 1.0, 'Test race');
        $facture->update(['montant_total' => 500.0]);

        $countAvant = Transaction::count();

        // Premier appel réussit
        $this->service->valider($facture);

        // Simule une 2e requête qui tente de valider la même facture (ex: double-clic)
        // En prod, lockForUpdate() sérialise et le second verrait statut=Validee
        // Ici on charge à nouveau la facture pour simuler l'état de la 2e requête
        $factureDuplicate = Facture::find($facture->id);

        try {
            $this->service->valider($factureDuplicate);
        } catch (RuntimeException $e) {
            // La 2e tentative doit lever (brouillon requis)
            expect($e->getMessage())->toContain('brouillon');
        }

        // Exactement 1 Transaction créée, pas 2
        expect(Transaction::count())->toBe($countAvant + 1);
    });
});

// ─── Test 5 : Encaissement de la transaction générée ────────────────────────

describe('Encaissement : la Transaction générée passe à "recu" via marquerReglementRecu', function () {

    it('après validation, marquerReglementRecu sur la transaction générée change le statut', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 800.0, 1.0, 'Mission');
        $facture->update(['montant_total' => 800.0]);

        $this->service->valider($facture);
        $facture->refresh();

        $transaction = Transaction::latest('id')->first();

        // Statut initial = EnAttente ("à recevoir")
        expect($transaction->statut_reglement)->toBe(StatutReglement::EnAttente);

        // Encaissement via le flow FactureService (marquerReglementRecu)
        $this->service->marquerReglementRecu($facture, [$transaction->id]);

        $transaction->refresh();
        expect($transaction->statut_reglement)->toBe(StatutReglement::Recu);
    });

    it('la facture est considérée comme acquittée après encaissement de la transaction générée', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 800.0, 1.0, 'Mission');
        $facture->update(['montant_total' => 800.0]);

        $this->service->valider($facture);
        $facture->refresh();

        $transaction = Transaction::latest('id')->first();

        $this->service->marquerReglementRecu($facture, [$transaction->id]);

        $facture->refresh();
        expect($facture->isAcquittee())->toBeTrue();
    });
});

// ─── Test 6 : Logs facture.valide ───────────────────────────────────────────

describe('Logs : facture.valide émis avec facture_id + transaction_id_generee', function () {

    it('émet facture.valide avec facture_id et transaction_id_generee pour une facture avec MontantLibre', function () {
        $facture = creerFactureLibre($this->service, $this->tiers->id);
        ajouterLigneLibre($facture, $this->sousCategorie, 600.0, 1.0, 'Presta');
        $facture->update(['montant_total' => 600.0]);

        $spy = Log::spy();

        $this->service->valider($facture);

        $facture->refresh();
        $transaction = Transaction::latest('id')->first();

        $expectedFactureId = (int) $facture->id;
        $expectedTxId = (int) $transaction->id;

        $spy->shouldHaveReceived('info')
            ->with(
                'facture.valide',
                Mockery::on(fn ($ctx) => (int) ($ctx['facture_id'] ?? 0) === $expectedFactureId
                    && (int) ($ctx['transaction_id_generee'] ?? 0) === $expectedTxId
                )
            )
            ->once();
    });

    it('émet facture.valide avec transaction_id_generee null pour une facture sans MontantLibre', function () {
        $transactionExistante = Transaction::factory()->asRecette()->create([
            'tiers_id' => $this->tiers->id,
            'montant_total' => 400.0,
        ]);

        $facture = $this->service->creer($this->tiers->id);
        $this->service->ajouterTransactions($facture, [$transactionExistante->id]);

        $spy = Log::spy();

        $this->service->valider($facture);

        $facture->refresh();
        $expectedFactureId = (int) $facture->id;

        $spy->shouldHaveReceived('info')
            ->with(
                'facture.valide',
                Mockery::on(fn ($ctx) => (int) ($ctx['facture_id'] ?? 0) === $expectedFactureId
                    && array_key_exists('transaction_id_generee', $ctx)
                    && $ctx['transaction_id_generee'] === null
                )
            )
            ->once();
    });
});
