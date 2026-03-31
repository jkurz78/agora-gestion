<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\FactureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->compteBancaire = CompteBancaire::factory()->create();
    $this->service = app(FactureService::class);
});

/**
 * Helper: create a recette transaction with lignes for the given tiers.
 */
function createRecetteTransaction(
    int $tiersId,
    int $compteId,
    int $saisiPar,
    float $montant = 100.00,
    string $libelle = 'Test recette',
    string $date = '2025-11-15',
    ?TypeTransaction $type = null,
): Transaction {
    return Transaction::create([
        'type' => $type ?? TypeTransaction::Recette,
        'date' => $date,
        'libelle' => $libelle,
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Virement,
        'compte_id' => $compteId,
        'tiers_id' => $tiersId,
        'saisi_par' => $saisiPar,
    ]);
}

describe('creer()', function () {
    it('creates a brouillon facture with correct defaults from association', function () {
        $compte = CompteBancaire::factory()->create();
        Association::create([
            'nom' => 'Mon Asso',
            'facture_conditions_reglement' => 'Net 30 jours',
            'facture_mentions_legales' => 'Mentions personnalisées',
            'facture_compte_bancaire_id' => $compte->id,
        ]);

        $facture = $this->service->creer($this->tiers->id);

        expect($facture)->toBeInstanceOf(Facture::class)
            ->and($facture->statut)->toBe(StatutFacture::Brouillon)
            ->and($facture->numero)->toBeNull()
            ->and($facture->date->toDateString())->toBe(now()->toDateString())
            ->and($facture->tiers_id)->toBe($this->tiers->id)
            ->and($facture->conditions_reglement)->toBe('Net 30 jours')
            ->and($facture->mentions_legales)->toBe('Mentions personnalisées')
            ->and($facture->compte_bancaire_id)->toBe($compte->id)
            ->and($facture->saisi_par)->toBe($this->user->id)
            ->and((float) $facture->montant_total)->toBe(0.0);
    });

    it('uses default mentions when association has no facture settings', function () {
        $facture = $this->service->creer($this->tiers->id);

        expect($facture->conditions_reglement)->toBe('Payable à réception')
            ->and($facture->mentions_legales)->toBe("TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé")
            ->and($facture->compte_bancaire_id)->toBeNull();
    });

    it('throws if exercice is closed', function () {
        // Current date is 2026-03-31, so current exercice is 2025 (Sept 2025 - Aug 2026)
        Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'date_cloture' => now(),
        ]);

        $this->service->creer($this->tiers->id);
    })->throws(ExerciceCloturedException::class);
});

describe('ajouterTransactions()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create(['nom' => 'Inscription Yoga']);
        $this->operation = Operation::factory()->create(['nom' => 'Yoga Adultes']);
        $this->facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);
    });

    it('creates pivot entries and generates facture_lignes from transaction_lignes', function () {
        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 100.00, 'Paiement inscription',
        );

        $ligne1 = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'operation_id' => $this->operation->id,
            'montant' => 60.00,
        ]);

        $ligne2 = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'operation_id' => $this->operation->id,
            'montant' => 40.00,
        ]);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);

        // Check pivot
        expect($this->facture->transactions()->count())->toBe(1);

        // Check facture lignes
        $lignes = FactureLigne::where('facture_id', $this->facture->id)
            ->orderBy('ordre')
            ->get();

        expect($lignes)->toHaveCount(2)
            ->and($lignes[0]->transaction_ligne_id)->toBe($ligne1->id)
            ->and($lignes[0]->type)->toBe(TypeLigneFacture::Montant)
            ->and((float) $lignes[0]->montant)->toBe(60.0)
            ->and($lignes[0]->ordre)->toBe(1)
            ->and($lignes[1]->transaction_ligne_id)->toBe($ligne2->id)
            ->and((float) $lignes[1]->montant)->toBe(40.0)
            ->and($lignes[1]->ordre)->toBe(2);
    });

    it('generates correct auto-libellé with sous-catégorie, opération, séance', function () {
        Seance::create([
            'operation_id' => $this->operation->id,
            'numero' => 3,
            'date' => '2025-12-15',
        ]);

        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 50.00,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'operation_id' => $this->operation->id,
            'seance' => 3,
            'montant' => 30.00,
        ]);

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'operation_id' => $this->operation->id,
            'seance' => null,
            'montant' => 20.00,
        ]);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);

        $lignes = FactureLigne::where('facture_id', $this->facture->id)
            ->orderBy('ordre')
            ->get();

        expect($lignes[0]->libelle)->toBe('Inscription Yoga — Yoga Adultes — Séance 3 du 15/12/2025');
        expect($lignes[1]->libelle)->toBe('Inscription Yoga — Yoga Adultes');
    });

    it('generates libellé with séance but without date', function () {
        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 30.00,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'operation_id' => $this->operation->id,
            'seance' => 5,
            'montant' => 30.00,
        ]);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);

        $ligne = FactureLigne::where('facture_id', $this->facture->id)->first();
        expect($ligne->libelle)->toBe('Inscription Yoga — Yoga Adultes — Séance 5');
    });

    it('generates libellé without opération', function () {
        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 30.00,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'operation_id' => null,
            'montant' => 30.00,
        ]);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);

        $ligne = FactureLigne::where('facture_id', $this->facture->id)->first();
        expect($ligne->libelle)->toBe('Inscription Yoga');
    });

    it('rejects transactions not belonging to the facture tiers', function () {
        $autreTiers = Tiers::factory()->create();

        $transaction = createRecetteTransaction(
            $autreTiers->id, $this->compteBancaire->id, $this->user->id, 50.00,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'montant' => 50.00,
        ]);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);
    })->throws(RuntimeException::class, "n'appartient pas au même tiers");

    it('rejects transactions already linked to a non-annulée facture', function () {
        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 50.00,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'montant' => 50.00,
        ]);

        $autreFacture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 50.00,
        ]);
        $autreFacture->transactions()->attach($transaction->id);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);
    })->throws(RuntimeException::class, 'déjà liée à une facture non annulée');

    it('allows transactions linked to an annulée facture', function () {
        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 50.00,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'montant' => 50.00,
        ]);

        $autreFacture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Annulee,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 50.00,
        ]);
        $autreFacture->transactions()->attach($transaction->id);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);

        expect($this->facture->transactions()->count())->toBe(1);
    });

    it('rejects non-recette transactions', function () {
        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 50.00, 'Dépense', '2025-11-15', TypeTransaction::Depense,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'montant' => 50.00,
        ]);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);
    })->throws(RuntimeException::class, "n'est pas une recette");
});

describe('retirerTransaction()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);
    });

    it('removes pivot and deletes corresponding facture_lignes', function () {
        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'montant' => 100.00,
        ]);

        $this->service->ajouterTransactions($this->facture, [$transaction->id]);
        expect($this->facture->transactions()->count())->toBe(1);
        expect(FactureLigne::where('facture_id', $this->facture->id)->count())->toBe(1);

        $this->service->retirerTransaction($this->facture, $transaction->id);

        expect($this->facture->transactions()->count())->toBe(0);
        expect(FactureLigne::where('facture_id', $this->facture->id)->count())->toBe(0);
    });

    it('throws on non-brouillon facture', function () {
        $facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $this->service->retirerTransaction($facture, 1);
    })->throws(RuntimeException::class, 'brouillon');
});

describe('supprimerBrouillon()', function () {
    it('deletes facture, lignes, and pivot', function () {
        $sousCategorie = SousCategorie::factory()->create();
        $facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $transaction = createRecetteTransaction(
            $this->tiers->id, $this->compteBancaire->id, $this->user->id, 50.00,
        );

        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $sousCategorie->id,
            'montant' => 50.00,
        ]);

        $this->service->ajouterTransactions($facture, [$transaction->id]);

        $factureId = $facture->id;

        $this->service->supprimerBrouillon($facture);

        expect(Facture::find($factureId))->toBeNull();
        expect(FactureLigne::where('facture_id', $factureId)->count())->toBe(0);
        // Transaction still exists (not deleted)
        expect(Transaction::find($transaction->id))->not->toBeNull();
    });

    it('throws if facture is validée', function () {
        $facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $this->service->supprimerBrouillon($facture);
    })->throws(RuntimeException::class, 'brouillon');
});

describe('majOrdre()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        // Create 3 lines with sequential ordre
        $this->ligneA = FactureLigne::create([
            'facture_id' => $this->facture->id,
            'type' => TypeLigneFacture::Montant,
            'libelle' => 'Ligne A',
            'montant' => 10.00,
            'ordre' => 1,
        ]);
        $this->ligneB = FactureLigne::create([
            'facture_id' => $this->facture->id,
            'type' => TypeLigneFacture::Texte,
            'libelle' => 'Ligne B',
            'montant' => null,
            'ordre' => 2,
        ]);
        $this->ligneC = FactureLigne::create([
            'facture_id' => $this->facture->id,
            'type' => TypeLigneFacture::Montant,
            'libelle' => 'Ligne C',
            'montant' => 30.00,
            'ordre' => 3,
        ]);
    });

    it('swaps ordre of two adjacent lines going up', function () {
        $this->service->majOrdre($this->facture, $this->ligneB->id, 'up');

        expect($this->ligneB->fresh()->ordre)->toBe(1)
            ->and($this->ligneA->fresh()->ordre)->toBe(2);
    });

    it('swaps ordre of two adjacent lines going down', function () {
        $this->service->majOrdre($this->facture, $this->ligneB->id, 'down');

        expect($this->ligneB->fresh()->ordre)->toBe(3)
            ->and($this->ligneC->fresh()->ordre)->toBe(2);
    });

    it('does nothing at top boundary', function () {
        $this->service->majOrdre($this->facture, $this->ligneA->id, 'up');

        expect($this->ligneA->fresh()->ordre)->toBe(1)
            ->and($this->ligneB->fresh()->ordre)->toBe(2)
            ->and($this->ligneC->fresh()->ordre)->toBe(3);
    });

    it('does nothing at bottom boundary', function () {
        $this->service->majOrdre($this->facture, $this->ligneC->id, 'down');

        expect($this->ligneA->fresh()->ordre)->toBe(1)
            ->and($this->ligneB->fresh()->ordre)->toBe(2)
            ->and($this->ligneC->fresh()->ordre)->toBe(3);
    });

    it('throws on non-brouillon facture', function () {
        $facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $this->service->majOrdre($facture, 1, 'up');
    })->throws(RuntimeException::class, 'brouillon');
});

describe('majLibelle()', function () {
    beforeEach(function () {
        $this->facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);
    });

    it('updates the libellé of a line', function () {
        $ligne = FactureLigne::create([
            'facture_id' => $this->facture->id,
            'type' => TypeLigneFacture::Montant,
            'libelle' => 'Ancien libellé',
            'montant' => 50.00,
            'ordre' => 1,
        ]);

        $this->service->majLibelle($this->facture, $ligne->id, 'Nouveau libellé');

        expect($ligne->fresh()->libelle)->toBe('Nouveau libellé');
    });

    it('throws on non-brouillon facture', function () {
        $facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $this->service->majLibelle($facture, 1, 'test');
    })->throws(RuntimeException::class, 'brouillon');
});

describe('ajouterLigneTexte()', function () {
    it('creates a texte line with correct ordre and null montant', function () {
        $facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        // Add an existing line so we can verify max+1
        FactureLigne::create([
            'facture_id' => $facture->id,
            'type' => TypeLigneFacture::Montant,
            'libelle' => 'Existing line',
            'montant' => 100.00,
            'ordre' => 3,
        ]);

        $this->service->ajouterLigneTexte($facture, 'Note importante');

        $texteLine = FactureLigne::where('facture_id', $facture->id)
            ->where('type', TypeLigneFacture::Texte)
            ->first();

        expect($texteLine)->not->toBeNull()
            ->and($texteLine->libelle)->toBe('Note importante')
            ->and($texteLine->montant)->toBeNull()
            ->and($texteLine->transaction_ligne_id)->toBeNull()
            ->and($texteLine->ordre)->toBe(4);
    });
});

describe('supprimerLigne()', function () {
    beforeEach(function () {
        $this->facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);
    });

    it('deletes a texte line', function () {
        $ligne = FactureLigne::create([
            'facture_id' => $this->facture->id,
            'type' => TypeLigneFacture::Texte,
            'libelle' => 'Ligne de texte',
            'montant' => null,
            'ordre' => 1,
        ]);

        $this->service->supprimerLigne($this->facture, $ligne->id);

        expect(FactureLigne::find($ligne->id))->toBeNull();
    });

    it('throws when trying to delete a montant line', function () {
        $ligne = FactureLigne::create([
            'facture_id' => $this->facture->id,
            'type' => TypeLigneFacture::Montant,
            'libelle' => 'Ligne montant',
            'montant' => 50.00,
            'ordre' => 1,
        ]);

        $this->service->supprimerLigne($this->facture, $ligne->id);
    })->throws(RuntimeException::class, 'lignes de texte');

    it('throws on non-brouillon facture', function () {
        $facture = Facture::create([
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $this->service->supprimerLigne($facture, 1);
    })->throws(RuntimeException::class, 'brouillon');
});
