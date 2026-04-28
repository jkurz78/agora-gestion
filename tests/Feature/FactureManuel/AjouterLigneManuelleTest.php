<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
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
    $this->facture = $this->service->creerManuelleVierge($this->tiers->id);
});

afterEach(function () {
    TenantContext::clear();
});

// ─── ajouterLigneManuelle ────────────────────────────────────────────────

describe('ajouterLigneManuelle()', function () {

    it('happy path — crée une ligne MontantManuel avec les bons attributs (PU=800, qty=3, sous_cat fournie)', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Mission audit',
            'prix_unitaire' => 800,
            'quantite' => 3,
            'sous_categorie_id' => $this->sousCategorie->id,
        ]);

        expect($ligne)->toBeInstanceOf(FactureLigne::class)
            ->and($ligne->exists)->toBeTrue()
            ->and($ligne->type)->toBe(TypeLigneFacture::MontantManuel)
            ->and((float) $ligne->montant)->toBe(2400.0)
            ->and($ligne->ordre)->toBe(1)
            ->and($ligne->transaction_ligne_id)->toBeNull()
            ->and((int) $ligne->sous_categorie_id)->toBe((int) $this->sousCategorie->id);

        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(2400.0);
    });

    it('happy path partiel — sans operation_id ni seance, ces champs sont null', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Prestation ponctuelle',
            'prix_unitaire' => 500,
            'quantite' => 1,
        ]);

        expect($ligne->operation_id)->toBeNull()
            ->and($ligne->seance)->toBeNull()
            ->and($ligne->exists)->toBeTrue();
    });

    it('ordre incrémental — deux ajouts successifs ont ordre 1 puis 2', function () {
        $ligne1 = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Ligne 1',
            'prix_unitaire' => 100,
            'quantite' => 1,
        ]);

        $ligne2 = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Ligne 2',
            'prix_unitaire' => 200,
            'quantite' => 1,
        ]);

        expect($ligne1->ordre)->toBe(1)
            ->and($ligne2->ordre)->toBe(2);
    });

    it('recalcul total — deux lignes 1000 + 500 → montant_total = 1500', function () {
        $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Ligne A',
            'prix_unitaire' => 1000,
            'quantite' => 1,
        ]);

        $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Ligne B',
            'prix_unitaire' => 500,
            'quantite' => 1,
        ]);

        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(1500.0);
    });

    it('guard prix_unitaire = 0 → exception message "strictement positif"', function () {
        expect(fn () => $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 0,
            'quantite' => 1,
        ]))->toThrow(RuntimeException::class, 'strictement positif');
    });

    it('guard prix_unitaire négatif → exception', function () {
        expect(fn () => $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => -100,
            'quantite' => 1,
        ]))->toThrow(RuntimeException::class);
    });

    it('guard quantite = 0 → exception message "strictement positif"', function () {
        expect(fn () => $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => 0,
        ]))->toThrow(RuntimeException::class, 'strictement positif');
    });

    it('guard quantite négative → exception', function () {
        expect(fn () => $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => -1,
        ]))->toThrow(RuntimeException::class);
    });

    it('guard facture validée → exception (non-brouillon)', function () {
        // Simuler une facture validée directement en base (contourne le service)
        $this->facture->update(['statut' => StatutFacture::Validee]);
        $this->facture->refresh();

        expect(fn () => $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => 1,
        ]))->toThrow(RuntimeException::class);
    });

    it('guard multi-tenant — facture d\'une autre association → exception', function () {
        $autreAssociation = Association::factory()->create();

        // Créer une facture cross-tenant directement en base
        $factureAutreAsso = Facture::withoutGlobalScopes()->create([
            'association_id' => $autreAssociation->id,
            'numero' => null,
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 0,
            'exercice' => date('Y'),
            'saisi_par' => $this->user->id,
        ]);

        expect(fn () => $this->service->ajouterLigneManuelle($factureAutreAsso, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => 1,
        ]))->toThrow(RuntimeException::class);
    });
});

// ─── ajouterLigneTexteManuelle ──────────────────────────────────────────────────

describe('ajouterLigneTexteManuelle()', function () {

    it('happy path — crée une ligne Texte avec les bons attributs', function () {
        $ligne = $this->service->ajouterLigneTexteManuelle($this->facture, 'Détail de la prestation');

        expect($ligne)->toBeInstanceOf(FactureLigne::class)
            ->and($ligne->exists)->toBeTrue()
            ->and($ligne->type)->toBe(TypeLigneFacture::Texte)
            ->and($ligne->libelle)->toBe('Détail de la prestation')
            ->and($ligne->montant)->toBeNull()
            ->and($ligne->prix_unitaire)->toBeNull()
            ->and($ligne->quantite)->toBeNull()
            ->and($ligne->sous_categorie_id)->toBeNull()
            ->and($ligne->operation_id)->toBeNull()
            ->and($ligne->seance)->toBeNull()
            ->and($ligne->transaction_ligne_id)->toBeNull()
            ->and($ligne->ordre)->toBe(1);

        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(0.0);
    });

    it('guard facture validée → exception (non-brouillon)', function () {
        $this->facture->update(['statut' => StatutFacture::Validee]);
        $this->facture->refresh();

        expect(fn () => $this->service->ajouterLigneTexteManuelle($this->facture, 'Texte'))
            ->toThrow(RuntimeException::class);
    });

    it('guard multi-tenant — facture d\'une autre association → exception', function () {
        $autreAssociation = Association::factory()->create();

        $factureAutreAsso = Facture::withoutGlobalScopes()->create([
            'association_id' => $autreAssociation->id,
            'numero' => null,
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'montant_total' => 0,
            'exercice' => date('Y'),
            'saisi_par' => $this->user->id,
        ]);

        expect(fn () => $this->service->ajouterLigneTexteManuelle($factureAutreAsso, 'Texte'))
            ->toThrow(RuntimeException::class);
    });
});

// ─── majPrixUnitaireLigneManuelle ─────────────────────────────────────────────

describe('majPrixUnitaireLigneManuelle()', function () {

    it('happy path — change PU recalcule montant ligne et total facture', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Prestation',
            'prix_unitaire' => 100,
            'quantite' => 3,
        ]);
        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(300.0);

        $this->service->majPrixUnitaireLigneManuelle($this->facture, $ligne->id, 200.0);

        $ligne->refresh();
        expect((float) $ligne->prix_unitaire)->toBe(200.0)
            ->and((float) $ligne->montant)->toBe(600.0);

        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(600.0);
    });

    it('guard PU = 0 → exception strictement positif', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => 1,
        ]);

        expect(fn () => $this->service->majPrixUnitaireLigneManuelle($this->facture, $ligne->id, 0.0))
            ->toThrow(RuntimeException::class, 'strictement positif');
    });

    it('guard PU négatif → exception', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => 1,
        ]);

        expect(fn () => $this->service->majPrixUnitaireLigneManuelle($this->facture, $ligne->id, -50.0))
            ->toThrow(RuntimeException::class);
    });

    it('guard ligne Texte → exception (PU sans sens sur ligne texte)', function () {
        $texte = $this->service->ajouterLigneTexteManuelle($this->facture, 'Mention');

        expect(fn () => $this->service->majPrixUnitaireLigneManuelle($this->facture, $texte->id, 100.0))
            ->toThrow(RuntimeException::class);
    });
});

// ─── majQuantiteLigneManuelle ─────────────────────────────────────────────────

describe('majQuantiteLigneManuelle()', function () {

    it('happy path — change qté recalcule montant ligne et total facture', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Formation',
            'prix_unitaire' => 500,
            'quantite' => 1,
        ]);
        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(500.0);

        $this->service->majQuantiteLigneManuelle($this->facture, $ligne->id, 4.0);

        $ligne->refresh();
        expect((float) $ligne->quantite)->toBe(4.0)
            ->and((float) $ligne->montant)->toBe(2000.0);

        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(2000.0);
    });

    it('guard qté = 0 → exception strictement positif', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => 1,
        ]);

        expect(fn () => $this->service->majQuantiteLigneManuelle($this->facture, $ligne->id, 0.0))
            ->toThrow(RuntimeException::class, 'strictement positive');
    });

    it('guard qté négative → exception', function () {
        $ligne = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Test',
            'prix_unitaire' => 100,
            'quantite' => 1,
        ]);

        expect(fn () => $this->service->majQuantiteLigneManuelle($this->facture, $ligne->id, -1.0))
            ->toThrow(RuntimeException::class);
    });

    it('guard ligne Texte → exception (qté sans sens sur ligne texte)', function () {
        $texte = $this->service->ajouterLigneTexteManuelle($this->facture, 'Mention');

        expect(fn () => $this->service->majQuantiteLigneManuelle($this->facture, $texte->id, 1.0))
            ->toThrow(RuntimeException::class);
    });
});

// ─── Mix des deux méthodes ───────────────────────────────────────────────────

describe('mix ajouterLigneManuelle + ajouterLigneTexteManuelle', function () {

    it('ajout montant puis texte → ordres 1 et 2 ; total = montant de la ligne montant', function () {
        $ligneMontant = $this->service->ajouterLigneManuelle($this->facture, [
            'libelle' => 'Prestation',
            'prix_unitaire' => 750,
            'quantite' => 2,
        ]);

        $ligneTexte = $this->service->ajouterLigneTexteManuelle($this->facture, 'Mention contractuelle');

        expect($ligneMontant->ordre)->toBe(1)
            ->and($ligneTexte->ordre)->toBe(2);

        $this->facture->refresh();
        expect((float) $this->facture->montant_total)->toBe(1500.0);
    });
});
