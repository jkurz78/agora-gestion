<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Enums\TypeLigneDevis;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;

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

describe('ajouterLigne()', function () {
    it('accepte un compte actif de classe 7 du tenant courant', function () {
        $devis = Devis::factory()->brouillon()->create();
        $compte = Compte::factory()->numero('706')->create();

        $ligne = $this->service->ajouterLigne($devis, [
            'libelle' => 'Prestation ventilée',
            'prix_unitaire' => 100,
            'quantite' => 1,
            'compte_id' => $compte->id,
        ]);

        expect((int) $ligne->compte_id)->toBe((int) $compte->id);
    });

    it('refuse les comptes externes, supprimés ou hors classe 7 avant toute écriture', function () {
        $devis = Devis::factory()->brouillon()->create();
        $compteClasse6 = Compte::factory()->numero('606')->create();
        $compteSupprime = Compte::factory()->numero('707')->create();
        $compteSupprime->delete();

        $autreAssociation = Association::factory()->create();
        TenantContext::boot($autreAssociation);
        $compteExterne = Compte::factory()->numero('708')->create();
        TenantContext::boot($this->association);

        foreach ([$compteClasse6, $compteSupprime, $compteExterne] as $compteInvalide) {
            expect(fn () => $this->service->ajouterLigne($devis, [
                'libelle' => 'Ligne invalide',
                'prix_unitaire' => 100,
                'quantite' => 1,
                'compte_id' => $compteInvalide->id,
            ]))->toThrow(RuntimeException::class, 'compte actif de classe 7');
        }

        expect(DevisLigne::where('devis_id', $devis->id)->count())->toBe(0);
    });
});

describe('modifierLigne()', function () {
    it('refuse un compte cross-tenant sans modifier la ligne', function () {
        $compteInitial = Compte::factory()->numero('706')->create();
        $devis = Devis::factory()->brouillon()->create();
        $ligne = DevisLigne::factory()->montant()->create([
            'devis_id' => $devis->id,
            'libelle' => 'Libellé initial',
            'compte_id' => $compteInitial->id,
        ]);

        $autreAssociation = Association::factory()->create();
        TenantContext::boot($autreAssociation);
        $compteExterne = Compte::factory()->numero('707')->create();
        TenantContext::boot($this->association);

        expect(fn () => $this->service->modifierLigne($ligne, [
            'libelle' => 'Libellé interdit',
            'compte_id' => $compteExterne->id,
        ]))->toThrow(RuntimeException::class, 'compte actif de classe 7');

        expect($ligne->fresh()->libelle)->toBe('Libellé initial')
            ->and((int) $ligne->fresh()->compte_id)->toBe((int) $compteInitial->id);
    });
});

// ─── ajouterLigneTexte ────────────────────────────────────────────────────────

describe('ajouterLigneTexte()', function () {
    it('crée une ligne de type texte avec uniquement un libellé', function () {
        $devis = Devis::factory()->brouillon()->create();

        $ligne = $this->service->ajouterLigneTexte($devis, 'Section A — Prestations détaillées');

        expect($ligne)->toBeInstanceOf(DevisLigne::class)
            ->and($ligne->libelle)->toBe('Section A — Prestations détaillées')
            ->and($ligne->type)->toBe(TypeLigneDevis::Texte)
            ->and($ligne->montant)->toBeNull()
            ->and($ligne->prix_unitaire)->toBeNull()
            ->and($ligne->quantite)->toBeNull()
            ->and($ligne->compte_id)->toBeNull();
    });

    it('n\'impacte pas le montant_total du devis', function () {
        $devis = Devis::factory()->brouillon()->create(['montant_total' => 500.00]);
        DevisLigne::factory()->montant()->create([
            'devis_id' => $devis->id,
            'montant' => 500.00,
            'ordre' => 1,
        ]);

        $this->service->ajouterLigneTexte($devis, 'Note importante');

        $devis->refresh();
        expect((float) $devis->montant_total)->toBe(500.00);
    });

    it('incrémente l\'ordre correctement après des lignes montant', function () {
        $devis = Devis::factory()->brouillon()->create();

        $this->service->ajouterLigne($devis, [
            'libelle' => 'Ligne 1',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]);

        $texte = $this->service->ajouterLigneTexte($devis, 'Section titre');

        expect($texte->ordre)->toBe(2);
    });

    it('repasse en Brouillon quand on ajoute une ligne texte sur un devis validé', function () {
        $devis = Devis::factory()->valide()->create();

        $this->service->ajouterLigneTexte($devis, 'Commentaire ajouté');

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('refuse d\'ajouter une ligne texte si statut est Accepte', function () {
        $devis = Devis::factory()->accepte()->create();

        expect(fn () => $this->service->ajouterLigneTexte($devis, 'Impossible'))
            ->toThrow(RuntimeException::class);
    });

    it('refuse d\'ajouter une ligne texte si statut est Refuse', function () {
        $devis = Devis::factory()->refuse()->create();

        expect(fn () => $this->service->ajouterLigneTexte($devis, 'Impossible'))
            ->toThrow(RuntimeException::class);
    });

    it('refuse d\'ajouter une ligne texte si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();

        expect(fn () => $this->service->ajouterLigneTexte($devis, 'Impossible'))
            ->toThrow(RuntimeException::class);
    });
});

// ─── majOrdre ────────────────────────────────────────────────────────────────

describe('majOrdre()', function () {
    it('déplace une ligne vers le haut en échangeant l\'ordre avec le prédécesseur', function () {
        $devis = Devis::factory()->brouillon()->create();

        $l1 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 1]);
        $l2 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 2]);
        $l3 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 3]);

        $this->service->majOrdre($devis, (int) $l3->id, 'up');

        expect($l3->fresh()->ordre)->toBe(2)
            ->and($l2->fresh()->ordre)->toBe(3)
            ->and($l1->fresh()->ordre)->toBe(1); // Inchangé
    });

    it('déplace une ligne vers le bas en échangeant l\'ordre avec le successeur', function () {
        $devis = Devis::factory()->brouillon()->create();

        $l1 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 1]);
        $l2 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 2]);
        $l3 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 3]);

        $this->service->majOrdre($devis, (int) $l1->id, 'down');

        expect($l1->fresh()->ordre)->toBe(2)
            ->and($l2->fresh()->ordre)->toBe(1)
            ->and($l3->fresh()->ordre)->toBe(3); // Inchangé
    });

    it('ne fait rien quand on monte la première ligne (no-op)', function () {
        $devis = Devis::factory()->brouillon()->create();

        $l1 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 1]);
        $l2 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 2]);

        $this->service->majOrdre($devis, (int) $l1->id, 'up');

        expect($l1->fresh()->ordre)->toBe(1)
            ->and($l2->fresh()->ordre)->toBe(2);
    });

    it('ne fait rien quand on descend la dernière ligne (no-op)', function () {
        $devis = Devis::factory()->brouillon()->create();

        $l1 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 1]);
        $l2 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 2]);

        $this->service->majOrdre($devis, (int) $l2->id, 'down');

        expect($l1->fresh()->ordre)->toBe(1)
            ->and($l2->fresh()->ordre)->toBe(2);
    });

    it('repasse en Brouillon quand on réordonne sur un devis validé', function () {
        $devis = Devis::factory()->valide()->create();

        $l1 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 1]);
        $l2 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 2]);

        $this->service->majOrdre($devis, (int) $l2->id, 'up');

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Brouillon);
    });

    it('refuse de réordonner si statut est Accepte', function () {
        $devis = Devis::factory()->accepte()->create();

        $l1 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 1]);
        $l2 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 2]);

        expect(fn () => $this->service->majOrdre($devis, (int) $l2->id, 'up'))
            ->toThrow(RuntimeException::class);
    });

    it('refuse de réordonner si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();

        $l1 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 1]);
        $l2 = DevisLigne::factory()->montant()->create(['devis_id' => $devis->id, 'ordre' => 2]);

        expect(fn () => $this->service->majOrdre($devis, (int) $l2->id, 'up'))
            ->toThrow(RuntimeException::class);
    });
});
