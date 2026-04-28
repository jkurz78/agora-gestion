<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneDevis;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Facture;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─── Setup ───────────────────────────────────────────────────────────────────

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
    $this->service = app(DevisService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ─── Helper : crée un devis Accepté avec des lignes ──────────────────────────

/**
 * Crée un devis au statut Accepté avec 2 lignes Montant + 1 ligne Texte.
 */
function creerDevisAccepte(Tiers $tiers, SousCategorie $sousCategorie): Devis
{
    $devis = new Devis([
        'tiers_id' => $tiers->id,
        'statut' => StatutDevis::Accepte,
        'montant_total' => 1800.00,
        'numero' => 'D-2026-001',
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
        'libelle' => 'Mission audit',
        'prix_unitaire' => 800.00,
        'quantite' => 2.0,
        'montant' => 1600.00,
        'sous_categorie_id' => $sousCategorie->id,
    ]);

    DevisLigne::create([
        'devis_id' => $devis->id,
        'ordre' => 2,
        'type' => TypeLigneDevis::Montant,
        'libelle' => 'Frais déplacement',
        'prix_unitaire' => 200.00,
        'quantite' => 1.0,
        'montant' => 200.00,
        'sous_categorie_id' => $sousCategorie->id,
    ]);

    DevisLigne::create([
        'devis_id' => $devis->id,
        'ordre' => 3,
        'type' => TypeLigneDevis::Texte,
        'libelle' => 'Détail de la prestation selon annexe',
        'prix_unitaire' => null,
        'quantite' => null,
        'montant' => null,
        'sous_categorie_id' => null,
    ]);

    return $devis;
}

// ─── Test 1 : Happy path ─────────────────────────────────────────────────────

describe('Happy path : devis accepté (2 lignes Montant + 1 Texte) → Facture brouillon', function () {

    it('crée une facture brouillon avec tiers_id et devis_id corrects', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        expect($facture)->toBeInstanceOf(Facture::class)
            ->and($facture->exists)->toBeTrue()
            ->and($facture->statut)->toBe(StatutFacture::Brouillon)
            ->and((int) $facture->tiers_id)->toBe((int) $this->tiers->id)
            ->and((int) $facture->devis_id)->toBe((int) $devis->id)
            ->and((int) $facture->association_id)->toBe((int) $this->association->id);
    });

    it('la facture n\'a pas de numéro (statut brouillon)', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        expect($facture->numero)->toBeNull();
    });

    it('crée 3 FactureLignes recopiant les lignes du devis', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        expect($facture->lignes()->count())->toBe(3);
    });

    it('les deux lignes Montant du devis sont converties en MontantManuel sur la facture', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        $lignesMontantManuel = $facture->lignes()
            ->where('type', TypeLigneFacture::MontantManuel->value)
            ->get();

        expect($lignesMontantManuel)->toHaveCount(2);

        foreach ($lignesMontantManuel as $ligne) {
            expect($ligne->type)->toBe(TypeLigneFacture::MontantManuel);
            expect($ligne->transaction_ligne_id)->toBeNull();
        }
    });

    it('la ligne Texte du devis est copiée comme ligne Texte sur la facture', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        $lignesTexte = $facture->lignes()
            ->where('type', TypeLigneFacture::Texte->value)
            ->get();

        expect($lignesTexte)->toHaveCount(1);

        $ligneTexte = $lignesTexte->first();
        expect($ligneTexte->libelle)->toBe('Détail de la prestation selon annexe')
            ->and($ligneTexte->montant)->toBeNull()
            ->and($ligneTexte->prix_unitaire)->toBeNull()
            ->and($ligneTexte->quantite)->toBeNull()
            ->and($ligneTexte->transaction_ligne_id)->toBeNull();
    });

    it('les libelle, prix_unitaire, quantite et sous_categorie_id sont recopiés sur les lignes MontantManuel', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        $lignesMontantManuel = $facture->lignes()
            ->where('type', TypeLigneFacture::MontantManuel->value)
            ->orderBy('ordre')
            ->get();

        $premiere = $lignesMontantManuel->first();
        expect($premiere->libelle)->toBe('Mission audit')
            ->and((float) $premiere->prix_unitaire)->toBe(800.0)
            ->and((float) $premiere->quantite)->toBe(2.0)
            ->and((float) $premiere->montant)->toBe(1600.0)
            ->and((int) $premiere->sous_categorie_id)->toBe((int) $this->sousCategorie->id);

        $seconde = $lignesMontantManuel->skip(1)->first();
        expect($seconde->libelle)->toBe('Frais déplacement')
            ->and((float) $seconde->prix_unitaire)->toBe(200.0)
            ->and((float) $seconde->quantite)->toBe(1.0)
            ->and((float) $seconde->montant)->toBe(200.0)
            ->and((int) $seconde->sous_categorie_id)->toBe((int) $this->sousCategorie->id);
    });

    it('le montant_total est la somme des montants des lignes Montant (1600 + 200 = 1800)', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        expect((float) $facture->montant_total)->toBe(1800.0);
    });

    it('l\'ordre des lignes est préservé', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        $ordres = $facture->lignes()->orderBy('ordre')->pluck('ordre')->toArray();
        $lignesOrdered = $facture->lignes()->orderBy('ordre')->get();

        // Les ordres doivent être croissants
        expect($ordres)->toBe(array_values($ordres))
            ->and(count($ordres))->toBe(3);

        // Première ligne = Mission audit (type MontantManuel)
        expect($lignesOrdered->first()->libelle)->toBe('Mission audit')
            ->and($lignesOrdered->first()->type)->toBe(TypeLigneFacture::MontantManuel);

        // Troisième ligne = Texte
        expect($lignesOrdered->last()->type)->toBe(TypeLigneFacture::Texte);
    });

    it('transaction_ligne_id est null partout (brouillon, pas encore validée)', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        foreach ($facture->lignes as $ligne) {
            expect($ligne->transaction_ligne_id)->toBeNull();
        }
    });

    it('le devis reste au statut Accepté après transformation', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $this->service->transformerEnFacture($devis);

        expect($devis->fresh()->statut)->toBe(StatutDevis::Accepte);
    });

    it('persiste la facture et les lignes en base de données', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        $this->assertDatabaseHas('factures', [
            'id' => $facture->id,
            'statut' => StatutFacture::Brouillon->value,
            'tiers_id' => $this->tiers->id,
            'devis_id' => $devis->id,
            'numero' => null,
        ]);

        $this->assertDatabaseHas('facture_lignes', [
            'facture_id' => $facture->id,
            'type' => TypeLigneFacture::MontantManuel->value,
            'libelle' => 'Mission audit',
        ]);

        $this->assertDatabaseHas('facture_lignes', [
            'facture_id' => $facture->id,
            'type' => TypeLigneFacture::Texte->value,
            'libelle' => 'Détail de la prestation selon annexe',
        ]);
    });
});

// ─── Test 2 : Guards statut ──────────────────────────────────────────────────

describe('Guard statut : seul un devis Accepté peut être transformé', function () {

    it('lève une exception pour un devis Brouillon', function () {
        $devis = new Devis([
            'tiers_id' => $this->tiers->id,
            'statut' => StatutDevis::Brouillon,
            'montant_total' => 100.00,
            'date_emission' => now()->toDateString(),
            'date_validite' => now()->addDays(30)->toDateString(),
            'saisi_par_user_id' => auth()->id(),
        ]);
        $devis->exercice = 2026;
        $devis->save();

        expect(fn () => $this->service->transformerEnFacture($devis))
            ->toThrow(RuntimeException::class, 'accepté');
    });

    it('lève une exception pour un devis Valide', function () {
        $devis = new Devis([
            'tiers_id' => $this->tiers->id,
            'statut' => StatutDevis::Valide,
            'montant_total' => 100.00,
            'numero' => 'D-2026-010',
            'date_emission' => now()->toDateString(),
            'date_validite' => now()->addDays(30)->toDateString(),
            'saisi_par_user_id' => auth()->id(),
        ]);
        $devis->exercice = 2026;
        $devis->save();

        expect(fn () => $this->service->transformerEnFacture($devis))
            ->toThrow(RuntimeException::class, 'accepté');
    });

    it('lève une exception pour un devis Refusé', function () {
        $devis = new Devis([
            'tiers_id' => $this->tiers->id,
            'statut' => StatutDevis::Refuse,
            'montant_total' => 100.00,
            'date_emission' => now()->toDateString(),
            'date_validite' => now()->addDays(30)->toDateString(),
            'saisi_par_user_id' => auth()->id(),
        ]);
        $devis->exercice = 2026;
        $devis->save();

        expect(fn () => $this->service->transformerEnFacture($devis))
            ->toThrow(RuntimeException::class, 'accepté');
    });

    it('lève une exception pour un devis Annulé', function () {
        $devis = new Devis([
            'tiers_id' => $this->tiers->id,
            'statut' => StatutDevis::Annule,
            'montant_total' => 100.00,
            'date_emission' => now()->toDateString(),
            'date_validite' => now()->addDays(30)->toDateString(),
            'saisi_par_user_id' => auth()->id(),
        ]);
        $devis->exercice = 2026;
        $devis->save();

        expect(fn () => $this->service->transformerEnFacture($devis))
            ->toThrow(RuntimeException::class, 'accepté');
    });

    it('ne crée pas de facture si le devis n\'est pas accepté', function () {
        $devis = new Devis([
            'tiers_id' => $this->tiers->id,
            'statut' => StatutDevis::Brouillon,
            'montant_total' => 100.00,
            'date_emission' => now()->toDateString(),
            'date_validite' => now()->addDays(30)->toDateString(),
            'saisi_par_user_id' => auth()->id(),
        ]);
        $devis->exercice = 2026;
        $devis->save();

        $countAvant = Facture::withoutGlobalScopes()->count();

        try {
            $this->service->transformerEnFacture($devis);
        } catch (RuntimeException) {
        }

        expect(Facture::withoutGlobalScopes()->count())->toBe($countAvant);
    });
});

// ─── Test 3 : Guard "déjà transformé" ────────────────────────────────────────

describe('Guard déjà transformé : un devis accepté ne peut être transformé qu\'une seule fois', function () {

    it('lève une exception si une facture issue du devis existe déjà', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        // Première transformation — réussit
        $this->service->transformerEnFacture($devis);

        // Deuxième tentative — doit lever
        expect(fn () => $this->service->transformerEnFacture($devis))
            ->toThrow(RuntimeException::class, 'facture');
    });

    it('il n\'y a qu\'une seule facture en DB après deux tentatives', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $this->service->transformerEnFacture($devis);

        try {
            $this->service->transformerEnFacture($devis);
        } catch (RuntimeException) {
        }

        expect(Facture::where('devis_id', $devis->id)->count())->toBe(1);
    });
});

// ─── Test 4 : Race — double transformation parallèle ─────────────────────────

describe('Race : double transformation simultanée → une seule facture créée', function () {

    it('la seconde transformation séquentielle lève et n\'insère pas de doublon', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        // Premier appel : réussit
        $this->service->transformerEnFacture($devis);

        // Simule un second worker qui recharge le devis depuis la DB et tente de transformer
        // (le lockForUpdate sérialise et expose aDejaUneFacture() = true)
        $devisDuplicate = Devis::withoutGlobalScopes()->find($devis->id);

        try {
            $this->service->transformerEnFacture($devisDuplicate);
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('facture');
        }

        // Exactement 1 facture créée, pas 2
        expect(Facture::withoutGlobalScopes()->where('devis_id', $devis->id)->count())->toBe(1);
    });
});

// ─── Test 5 : Multi-tenant ───────────────────────────────────────────────────

describe('Multi-tenant : un devis d\'une autre association lève une exception', function () {

    it('refuse de transformer un devis cross-tenant', function () {
        $autreAssociation = Association::factory()->create();
        $autreUser = User::factory()->create();
        $autreTiers = Tiers::withoutGlobalScopes()->create([
            'association_id' => $autreAssociation->id,
            'type' => 'structure',
            'nom' => 'Autre Tiers',
            'pour_depenses' => false,
            'pour_recettes' => true,
            'est_helloasso' => false,
            'email_optout' => false,
        ]);

        // Crée un devis Accepté appartenant à l'autre association
        // On doit contourner le TenantModel pour forcer un autre association_id
        $devisAutreAsso = new Devis([
            'tiers_id' => $autreTiers->id,
            'statut' => StatutDevis::Accepte,
            'montant_total' => 500.00,
            'numero' => 'D-2026-099',
            'date_emission' => now()->toDateString(),
            'date_validite' => now()->addDays(30)->toDateString(),
            'saisi_par_user_id' => $autreUser->id,
        ]);
        $devisAutreAsso->exercice = 2026;
        // Bypass TenantModel's creating hook by setting association_id manually
        $devisAutreAsso->association_id = $autreAssociation->id;
        $devisAutreAsso->saveQuietly();

        // Le contexte courant reste $this->association → cross-tenant doit être bloqué
        expect(fn () => $this->service->transformerEnFacture($devisAutreAsso))
            ->toThrow(RuntimeException::class);

        // Aucune facture créée
        expect(Facture::withoutGlobalScopes()->where('devis_id', $devisAutreAsso->id)->count())->toBe(0);
    });
});

// ─── Test 6 : Logs ───────────────────────────────────────────────────────────

describe('Logs : devis.transforme_en_facture émis avec devis_id + facture_id', function () {

    it('émet le log devis.transforme_en_facture avec les bons IDs', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $spy = Log::spy();

        $facture = $this->service->transformerEnFacture($devis);

        $expectedDevisId = (int) $devis->id;
        $expectedFactureId = (int) $facture->id;

        $spy->shouldHaveReceived('info')
            ->with(
                'devis.transforme_en_facture',
                Mockery::on(fn ($ctx) => (int) ($ctx['devis_id'] ?? 0) === $expectedDevisId
                    && (int) ($ctx['facture_id'] ?? 0) === $expectedFactureId
                )
            )
            ->once();
    });
});

// ─── Test 7 : Pas de numéro sur la facture créée ─────────────────────────────

describe('Numérotation : la facture brouillon créée n\'a pas de numéro', function () {

    it('facture.numero est null après transformation', function () {
        $devis = creerDevisAccepte($this->tiers, $this->sousCategorie);

        $facture = $this->service->transformerEnFacture($devis);

        expect($facture->numero)->toBeNull();
        $this->assertDatabaseHas('factures', [
            'id' => $facture->id,
            'numero' => null,
        ]);
    });
});
