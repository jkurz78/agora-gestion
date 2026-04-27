<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create([
        'devis_validite_jours' => 30,
    ]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->service = app(DevisService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ─── ajouterLigne ──────────────────────────────────────────────────────────────

describe('ajouterLigne()', function () {
    it('crée une ligne avec montant = prix_unitaire × quantite et met à jour montant_total', function () {
        $devis = Devis::factory()->brouillon()->create();

        $ligne = $this->service->ajouterLigne($devis, [
            'libelle' => 'Mission audit',
            'prix_unitaire' => 800.00,
            'quantite' => 3.0,
        ]);

        expect($ligne)->toBeInstanceOf(DevisLigne::class)
            ->and($ligne->libelle)->toBe('Mission audit')
            ->and((float) $ligne->montant)->toBe(2400.00)
            ->and((float) $ligne->prix_unitaire)->toBe(800.00)
            ->and((float) $ligne->quantite)->toBe(3.0);

        $devis->refresh();
        expect((float) $devis->montant_total)->toBe(2400.00);
    });

    it('incrémente l\'ordre correctement sur plusieurs lignes successives', function () {
        $devis = Devis::factory()->brouillon()->create();

        $l1 = $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne 1',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]);

        $l2 = $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne 2',
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
        ]);

        $l3 = $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne 3',
            'prix_unitaire' => 300.00,
            'quantite' => 1.0,
        ]);

        expect($l1->ordre)->toBe(1)
            ->and($l2->ordre)->toBe(2)
            ->and($l3->ordre)->toBe(3);
    });

    it('accepte sous_categorie_id null', function () {
        $devis = Devis::factory()->brouillon()->create();

        $ligne = $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne sans catégorie',
            'prix_unitaire' => 50.00,
            'quantite' => 1.0,
            'sous_categorie_id' => null,
        ]);

        expect($ligne->sous_categorie_id)->toBeNull();
    });

    it('accepte sous_categorie_id réel', function () {
        $devis = Devis::factory()->brouillon()->create();
        $sousCategorie = SousCategorie::factory()->create();

        $ligne = $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne avec catégorie',
            'prix_unitaire' => 150.00,
            'quantite' => 2.0,
            'sous_categorie_id' => $sousCategorie->id,
        ]);

        expect((int) $ligne->sous_categorie_id)->toBe((int) $sousCategorie->id);
    });

    it('additionne correctement plusieurs lignes dans montant_total', function () {
        $devis = Devis::factory()->brouillon()->create();

        $this->service->ajouterLigne($devis, ['libelle' => 'A', 'prix_unitaire' => 100.00, 'quantite' => 2.0]);
        $this->service->ajouterLigne($devis, ['libelle' => 'B', 'prix_unitaire' => 50.00, 'quantite' => 4.0]);

        $devis->refresh();
        // 100 × 2 + 50 × 4 = 200 + 200 = 400
        expect((float) $devis->montant_total)->toBe(400.00);
    });

    it('utilise quantite = 1 si non fournie', function () {
        $devis = Devis::factory()->brouillon()->create();

        $ligne = $this->service->ajouterLigne($devis, [
            'libelle' => 'Service',
            'prix_unitaire' => 300.00,
        ]);

        expect((float) $ligne->quantite)->toBe(1.0)
            ->and((float) $ligne->montant)->toBe(300.00);
    });

    it('permet d\'ajouter une ligne sur un devis envoyé (sans rebascule — Step 6)', function () {
        $devis = Devis::factory()->envoye()->create();

        $this->service->ajouterLigne($devis, [
            'libelle' => 'Nouvelle ligne',
            'prix_unitaire' => 99.00,
            'quantite' => 1.0,
        ]);

        // Step 6 ajoutera le rebascule. Pour l'instant le statut reste inchangé.
        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Envoye);
    });

    it('refuse d\'ajouter une ligne si statut est Accepte', function () {
        $devis = Devis::factory()->accepte()->create();

        expect(fn () => $this->service->ajouterLigne($devis, [
            'libelle' => 'Impossible',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]))->toThrow(RuntimeException::class);
    });

    it('refuse d\'ajouter une ligne si statut est Refuse', function () {
        $devis = Devis::factory()->refuse()->create();

        expect(fn () => $this->service->ajouterLigne($devis, [
            'libelle' => 'Impossible',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]))->toThrow(RuntimeException::class);
    });

    it('refuse d\'ajouter une ligne si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();

        expect(fn () => $this->service->ajouterLigne($devis, [
            'libelle' => 'Impossible',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]))->toThrow(RuntimeException::class);
    });
});

// ─── modifierLigne ────────────────────────────────────────────────────────────

describe('modifierLigne()', function () {
    it('recalcule montant de la ligne et montant_total du devis parent', function () {
        $devis = Devis::factory()->brouillon()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 2.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 200.00]);

        $this->service->modifierLigne($ligne, [
            'prix_unitaire' => 150.00,
            'quantite' => 3.0,
        ]);

        $ligne->refresh();
        $devis->refresh();

        expect((float) $ligne->montant)->toBe(450.00)
            ->and((float) $devis->montant_total)->toBe(450.00);
    });

    it('ne modifie que les champs fournis (libelle, sous_categorie_id)', function () {
        $devis = Devis::factory()->brouillon()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'libelle' => 'Libellé original',
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 200.00]);

        $this->service->modifierLigne($ligne, ['libelle' => 'Libellé modifié']);

        $ligne->refresh();

        expect($ligne->libelle)->toBe('Libellé modifié')
            ->and((float) $ligne->prix_unitaire)->toBe(200.00)
            ->and((float) $ligne->montant)->toBe(200.00);
    });

    it('recalcule montant_total avec plusieurs lignes', function () {
        $devis = Devis::factory()->brouillon()->create();
        $l1 = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 2.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 50.00,
            'quantite' => 1.0,
            'montant' => 50.00,
            'ordre' => 2,
        ]);
        $devis->update(['montant_total' => 250.00]);

        // Modifier seulement la première ligne
        $this->service->modifierLigne($l1, ['prix_unitaire' => 300.00, 'quantite' => 1.0]);

        $devis->refresh();
        // l1: 300 × 1 = 300 ; l2: 50 × 1 = 50 → total = 350
        expect((float) $devis->montant_total)->toBe(350.00);
    });

    it('permet la modification sur un devis envoyé (sans rebascule — Step 6)', function () {
        $devis = Devis::factory()->envoye()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        $this->service->modifierLigne($ligne, ['libelle' => 'Mise à jour']);

        // Step 6 ajoutera le rebascule. Pour l'instant le statut reste inchangé.
        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Envoye);
    });

    it('refuse de modifier une ligne si statut est Accepte', function () {
        $devis = Devis::factory()->accepte()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        expect(fn () => $this->service->modifierLigne($ligne, ['libelle' => 'Impossible']))
            ->toThrow(RuntimeException::class);
    });

    it('refuse de modifier une ligne si statut est Refuse', function () {
        $devis = Devis::factory()->refuse()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        expect(fn () => $this->service->modifierLigne($ligne, ['libelle' => 'Impossible']))
            ->toThrow(RuntimeException::class);
    });

    it('refuse de modifier une ligne si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        expect(fn () => $this->service->modifierLigne($ligne, ['libelle' => 'Impossible']))
            ->toThrow(RuntimeException::class);
    });
});

// ─── supprimerLigne ───────────────────────────────────────────────────────────

describe('supprimerLigne()', function () {
    it('supprime la ligne et recalcule montant_total', function () {
        $devis = Devis::factory()->brouillon()->create();
        $l1 = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $l2 = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 2,
        ]);
        $devis->update(['montant_total' => 300.00]);

        $this->service->supprimerLigne($l1);

        expect(DevisLigne::find($l1->id))->toBeNull();

        $devis->refresh();
        expect((float) $devis->montant_total)->toBe(100.00);
    });

    it('produit montant_total = 0.00 quand on supprime la seule ligne', function () {
        $devis = Devis::factory()->brouillon()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 500.00,
            'quantite' => 2.0,
            'montant' => 1000.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 1000.00]);

        $this->service->supprimerLigne($ligne);

        $devis->refresh();
        expect((float) $devis->montant_total)->toBe(0.00);
    });

    it('permet de supprimer une ligne sur un devis envoyé (sans rebascule — Step 6)', function () {
        $devis = Devis::factory()->envoye()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        $this->service->supprimerLigne($ligne);

        // Step 6 ajoutera le rebascule. Pour l'instant le statut reste inchangé.
        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Envoye);
    });

    it('refuse de supprimer une ligne si statut est Accepte', function () {
        $devis = Devis::factory()->accepte()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        expect(fn () => $this->service->supprimerLigne($ligne))
            ->toThrow(RuntimeException::class);
    });

    it('refuse de supprimer une ligne si statut est Refuse', function () {
        $devis = Devis::factory()->refuse()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        expect(fn () => $this->service->supprimerLigne($ligne))
            ->toThrow(RuntimeException::class);
    });

    it('refuse de supprimer une ligne si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        expect(fn () => $this->service->supprimerLigne($ligne))
            ->toThrow(RuntimeException::class);
    });
});
