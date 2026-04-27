<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
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
    $this->service = app(DevisService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ─── ajouterLigne sur devis Valide ────────────────────────────────────────────

describe('ajouterLigne() sur devis Valide — rebascule Brouillon', function () {
    it('repasse le statut à Brouillon quand on ajoute une ligne sur un devis envoyé', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-001']);

        $this->service->ajouterLigne($devis, [
            'libelle' => 'Nouvelle ligne',
            'prix_unitaire' => 99.00,
            'quantite' => 1.0,
        ]);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('conserve le numéro existant après rebascule en brouillon via ajouterLigne', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-007']);

        $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne supplémentaire',
            'prix_unitaire' => 150.00,
            'quantite' => 2.0,
        ]);

        $devis->refresh();
        expect($devis->numero)->toBe('D-2026-007')
            ->and($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('recalcule montant_total après ajout de ligne sur devis envoyé', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-001']);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        $this->service->ajouterLigne($devis, [
            'libelle' => 'Seconde ligne',
            'prix_unitaire' => 50.00,
            'quantite' => 2.0,
        ]);

        $devis->refresh();
        expect((float) $devis->montant_total)->toBe(200.00)
            ->and($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('synchronise l\'instance appelante après rebascule via ajouterLigne', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-001']);

        $this->service->ajouterLigne($devis, [
            'libelle' => 'Test sync',
            'prix_unitaire' => 10.00,
            'quantite' => 1.0,
        ]);

        // Instance doit refléter Brouillon sans refresh() explicite
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });
});

// ─── modifierLigne sur devis Valide ───────────────────────────────────────────

describe('modifierLigne() sur devis Valide — rebascule Brouillon', function () {
    it('repasse le statut à Brouillon quand on modifie une ligne sur un devis envoyé', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-002']);
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        $this->service->modifierLigne($ligne, ['libelle' => 'Libellé corrigé']);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('conserve le numéro existant après rebascule en brouillon via modifierLigne', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-042']);
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 200.00]);

        $this->service->modifierLigne($ligne, ['prix_unitaire' => 250.00]);

        $devis->refresh();
        expect($devis->numero)->toBe('D-2026-042')
            ->and($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('recalcule montant_total après modification de ligne sur devis envoyé', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-003']);
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 2.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 200.00]);

        $this->service->modifierLigne($ligne, ['prix_unitaire' => 300.00, 'quantite' => 1.0]);

        $devis->refresh();
        expect((float) $devis->montant_total)->toBe(300.00)
            ->and($devis->statut)->toBe(StatutDevis::Brouillon);
    });
});

// ─── supprimerLigne sur devis Valide ─────────────────────────────────────────

describe('supprimerLigne() sur devis Valide — rebascule Brouillon', function () {
    it('repasse le statut à Brouillon quand on supprime une ligne sur un devis envoyé', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-004']);
        $l1 = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'ordre' => 2,
        ]);
        $devis->update(['montant_total' => 300.00]);

        $this->service->supprimerLigne($l1);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('conserve le numéro existant après rebascule en brouillon via supprimerLigne', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-005']);
        $l1 = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 500.00,
            'quantite' => 1.0,
            'montant' => 500.00,
            'ordre' => 1,
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 2,
        ]);
        $devis->update(['montant_total' => 600.00]);

        $this->service->supprimerLigne($l1);

        $devis->refresh();
        expect($devis->numero)->toBe('D-2026-005')
            ->and($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('recalcule montant_total après suppression de ligne sur devis envoyé', function () {
        $devis = Devis::factory()->valide()->create(['numero' => 'D-2026-006']);
        $l1 = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 400.00,
            'quantite' => 1.0,
            'montant' => 400.00,
            'ordre' => 1,
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 2,
        ]);
        $devis->update(['montant_total' => 500.00]);

        $this->service->supprimerLigne($l1);

        $devis->refresh();
        expect((float) $devis->montant_total)->toBe(100.00)
            ->and($devis->statut)->toBe(StatutDevis::Brouillon);
    });
});

// ─── Pas de rebascule sur Brouillon ───────────────────────────────────────────

describe('opérations sur devis Brouillon — statut inchangé', function () {
    it('statut reste Brouillon après ajouterLigne sur devis brouillon', function () {
        $devis = Devis::factory()->brouillon()->create(['numero' => null]);

        $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne',
            'prix_unitaire' => 80.00,
            'quantite' => 1.0,
        ]);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('statut reste Brouillon après modifierLigne sur devis brouillon', function () {
        $devis = Devis::factory()->brouillon()->create(['numero' => null]);
        $ligne = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);

        $this->service->modifierLigne($ligne, ['libelle' => 'Changé']);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('statut reste Brouillon après supprimerLigne sur devis brouillon', function () {
        $devis = Devis::factory()->brouillon()->create(['numero' => null]);
        $l1 = DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 50.00,
            'quantite' => 1.0,
            'montant' => 50.00,
            'ordre' => 2,
        ]);
        $devis->update(['montant_total' => 150.00]);

        $this->service->supprimerLigne($l1);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });
});

// ─── Guards toujours actifs ───────────────────────────────────────────────────

describe('guards statuts verrouillés — toujours actifs après Step 6', function () {
    it('ajouterLigne sur devis Accepte lève RuntimeException', function () {
        $devis = Devis::factory()->accepte()->create();

        expect(fn () => $this->service->ajouterLigne($devis, [
            'libelle' => 'Interdit',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]))->toThrow(RuntimeException::class);
    });
});

// ─── Numéro immuable : cycle emit → rebascule → re-émet ──────────────────────

describe('numéro immuable sur le cycle envoi → rebascule → re-envoi', function () {
    it('conserve D-{exo}-001 après rebascule + re-émission', function () {
        // 1. Créer un devis brouillon et l'émettre → D-{exo}-001
        $devis = Devis::factory()->brouillon()->create(['exercice' => 2026]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        $this->service->marquerValide($devis);
        $devis->refresh();
        $numeroOriginal = $devis->numero;
        expect($numeroOriginal)->toBe('D-2026-001');

        // 2. Modifier une ligne → rebascule en Brouillon, numéro conservé
        $ligne = $devis->lignes()->first();
        $this->service->modifierLigne($ligne, ['libelle' => 'Modification']);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon)
            ->and($devis->numero)->toBe('D-2026-001');

        // 3. Re-émettre → statut Valide, numéro toujours D-2026-001 (conservé)
        $this->service->marquerValide($devis);
        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Valide)
            ->and($devis->numero)->toBe('D-2026-001');
    });
});
