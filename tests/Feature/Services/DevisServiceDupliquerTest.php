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
use Carbon\Carbon;
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
    Carbon::setTestNow();
});

// ─── Duplication depuis chaque statut ─────────────────────────────────────────

describe('dupliquer() — statuts sources', function () {
    it('duplique un devis Brouillon et retourne un nouveau Brouillon', function () {
        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau)->toBeInstanceOf(Devis::class)
            ->and($nouveau->statut)->toBe(StatutDevis::Brouillon)
            ->and((int) $nouveau->id)->not->toBe((int) $source->id);
    });

    it('duplique un devis Valide et retourne un nouveau Brouillon', function () {
        $source = Devis::factory()->valide()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->statut)->toBe(StatutDevis::Brouillon);
    });

    it('duplique un devis Accepte et retourne un nouveau Brouillon', function () {
        $source = Devis::factory()->accepte()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->statut)->toBe(StatutDevis::Brouillon);
    });

    it('duplique un devis Refuse et retourne un nouveau Brouillon', function () {
        $source = Devis::factory()->refuse()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->statut)->toBe(StatutDevis::Brouillon);
    });

    it('duplique un devis Annule et retourne un nouveau Brouillon', function () {
        $source = Devis::factory()->annule()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->statut)->toBe(StatutDevis::Brouillon);
    });

    it('duplique un devis Valide avec date_validite passée (cas "expiré" UI) et retourne un nouveau Brouillon', function () {
        // Un devis "expiré" est un Valide dont la date_validite est dans le passé.
        // Le statut en base reste Valide — pas de valeur d'enum spécifique.
        Carbon::setTestNow('2026-05-01');

        $source = Devis::factory()->valide()->create([
            'date_validite' => Carbon::parse('2026-04-01'), // date passée
        ]);

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->statut)->toBe(StatutDevis::Brouillon);
    });
});

// ─── Dates et champs du nouveau devis ─────────────────────────────────────────

describe('dupliquer() — champs du nouveau devis', function () {
    it('nouveau devis a date_emission = aujourd\'hui et date_validite = aujourd\'hui + devis_validite_jours', function () {
        Carbon::setTestNow('2026-05-15');

        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->date_emission->toDateString())->toBe('2026-05-15')
            ->and($nouveau->date_validite->toDateString())->toBe('2026-06-14'); // +30j
    });

    it('nouveau devis a date_validite calculée selon devis_validite_jours de l\'asso', function () {
        $this->association->update(['devis_validite_jours' => 45]);
        TenantContext::boot($this->association->fresh());

        Carbon::setTestNow('2026-05-01');

        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->date_validite->toDateString())->toBe('2026-06-15'); // +45j
    });

    it('nouveau devis n\'a pas de numéro (null)', function () {
        $source = Devis::factory()->valide()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->numero)->toBeNull();
    });

    it('nouveau devis copie le libelle du source', function () {
        $source = Devis::factory()->brouillon()->create([
            'libelle' => 'Prestation formation 2026',
        ]);

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->libelle)->toBe('Prestation formation 2026');
    });

    it('nouveau devis copie le tiers_id du source', function () {
        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        expect((int) $nouveau->tiers_id)->toBe((int) $source->tiers_id);
    });

    it('nouveau devis a saisi_par_user_id = utilisateur connecté', function () {
        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        expect((int) $nouveau->saisi_par_user_id)->toBe((int) $this->user->id);
    });

    it('nouveau devis n\'a aucune trace accepte/refuse/annule', function () {
        $source = Devis::factory()->accepte()->create();

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->accepte_par_user_id)->toBeNull()
            ->and($nouveau->accepte_le)->toBeNull()
            ->and($nouveau->refuse_par_user_id)->toBeNull()
            ->and($nouveau->refuse_le)->toBeNull()
            ->and($nouveau->annule_par_user_id)->toBeNull()
            ->and($nouveau->annule_le)->toBeNull();
    });
});

// ─── Copie des lignes ──────────────────────────────────────────────────────────

describe('dupliquer() — lignes recopiées', function () {
    it('recopie 3 lignes avec libelle, prix_unitaire, quantite, montant, sous_categorie_id et ordre', function () {
        $source = Devis::factory()->brouillon()->create(['montant_total' => 0]);

        $sous_cat = SousCategorie::factory()->create();

        DevisLigne::factory()->create([
            'devis_id' => $source->id,
            'ordre' => 1,
            'libelle' => 'Prestation A',
            'prix_unitaire' => 100.00,
            'quantite' => 2.0,
            'montant' => 200.00,
            'sous_categorie_id' => $sous_cat->id,
        ]);

        DevisLigne::factory()->create([
            'devis_id' => $source->id,
            'ordre' => 2,
            'libelle' => 'Prestation B',
            'prix_unitaire' => 50.50,
            'quantite' => 3.0,
            'montant' => 151.50,
            'sous_categorie_id' => null,
        ]);

        DevisLigne::factory()->create([
            'devis_id' => $source->id,
            'ordre' => 3,
            'libelle' => 'Frais divers',
            'prix_unitaire' => 25.00,
            'quantite' => 1.0,
            'montant' => 25.00,
            'sous_categorie_id' => $sous_cat->id,
        ]);

        $nouveau = $this->service->dupliquer($source);

        $lignesNouveau = $nouveau->lignes()->orderBy('ordre')->get();

        expect($lignesNouveau)->toHaveCount(3);

        expect($lignesNouveau[0]->libelle)->toBe('Prestation A')
            ->and((float) $lignesNouveau[0]->prix_unitaire)->toBe(100.0)
            ->and((float) $lignesNouveau[0]->quantite)->toBe(2.0)
            ->and((float) $lignesNouveau[0]->montant)->toBe(200.0)
            ->and((int) $lignesNouveau[0]->sous_categorie_id)->toBe((int) $sous_cat->id)
            ->and((int) $lignesNouveau[0]->ordre)->toBe(1);

        expect($lignesNouveau[1]->libelle)->toBe('Prestation B')
            ->and((float) $lignesNouveau[1]->prix_unitaire)->toBe(50.5)
            ->and((float) $lignesNouveau[1]->quantite)->toBe(3.0)
            ->and((float) $lignesNouveau[1]->montant)->toBe(151.5)
            ->and($lignesNouveau[1]->sous_categorie_id)->toBeNull()
            ->and((int) $lignesNouveau[1]->ordre)->toBe(2);

        expect($lignesNouveau[2]->libelle)->toBe('Frais divers')
            ->and((float) $lignesNouveau[2]->prix_unitaire)->toBe(25.0)
            ->and((float) $lignesNouveau[2]->quantite)->toBe(1.0)
            ->and((float) $lignesNouveau[2]->montant)->toBe(25.0)
            ->and((int) $lignesNouveau[2]->sous_categorie_id)->toBe((int) $sous_cat->id)
            ->and((int) $lignesNouveau[2]->ordre)->toBe(3);
    });

    it('nouveau devis a montant_total cohérent avec les lignes copiées', function () {
        $source = Devis::factory()->brouillon()->create(['montant_total' => 350.00]);

        DevisLigne::factory()->create([
            'devis_id' => $source->id,
            'ordre' => 1,
            'libelle' => 'Ligne 1',
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'sous_categorie_id' => null,
        ]);

        DevisLigne::factory()->create([
            'devis_id' => $source->id,
            'ordre' => 2,
            'libelle' => 'Ligne 2',
            'prix_unitaire' => 150.00,
            'quantite' => 1.0,
            'montant' => 150.00,
            'sous_categorie_id' => null,
        ]);

        $nouveau = $this->service->dupliquer($source);

        expect((float) $nouveau->montant_total)->toBe(350.0);
    });

    it('duplique un devis sans aucune ligne (devis vide)', function () {
        $source = Devis::factory()->brouillon()->create(['montant_total' => 0]);

        $nouveau = $this->service->dupliquer($source);

        expect($nouveau->lignes()->count())->toBe(0)
            ->and((float) $nouveau->montant_total)->toBe(0.0);
    });
});

// ─── Isolation multi-tenant ────────────────────────────────────────────────────

describe('dupliquer() — isolation multi-tenant', function () {
    it('le nouveau devis appartient à la même association que le source', function () {
        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        expect((int) $nouveau->association_id)->toBe((int) $source->association_id)
            ->and((int) $nouveau->association_id)->toBe((int) $this->association->id);
    });

    it('le nouveau devis a un id différent du source (pas de FK parent_id)', function () {
        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        expect((int) $nouveau->id)->not->toBe((int) $source->id);

        // Vérifie qu'il n'y a pas de colonne parent_id (pas de lien retour)
        expect(array_keys($nouveau->getAttributes()))->not->toContain('parent_id');
    });

    it('persiste le nouveau devis en base de données', function () {
        $source = Devis::factory()->brouillon()->create();

        $nouveau = $this->service->dupliquer($source);

        $this->assertDatabaseHas('devis', [
            'id' => $nouveau->id,
            'statut' => StatutDevis::Brouillon->value,
            'tiers_id' => $source->tiers_id,
            'numero' => null,
            'association_id' => $this->association->id,
        ]);
    });
});
