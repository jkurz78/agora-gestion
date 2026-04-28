<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Livewire\FactureList;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ─── Helpers partagés ────────────────────────────────────────────────────────

/**
 * Crée une association avec un admin et booter le TenantContext dessus.
 * Retourne [association, user].
 */
function creerAssociationAdmin(): array
{
    $association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return [$association, $user];
}

/**
 * Crée une facture libre validée pour une association donnée,
 * en bypassant les scopes globaux (TenantModel).
 * Retourne la facture.
 */
function creerFactureValideeAssociation(Association $assoBprime, Tiers $tiersBprime): Facture
{
    $exercice = app(ExerciceService::class)->current();

    $facture = new Facture([
        'numero' => 'F-'.$exercice.'-9901',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiersBprime->id,
        'montant_total' => 500.0,
        'exercice' => $exercice,
        'saisi_par' => 1,
        'mode_paiement_prevu' => ModePaiement::Virement->value,
    ]);
    $facture->association_id = $assoBprime->id;
    $facture->saveQuietly();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantLibre,
        'libelle' => 'Prestation Asso B',
        'prix_unitaire' => 500.0,
        'quantite' => 1.0,
        'montant' => 500.0,
        'ordre' => 1,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => null,
        'operation_id' => null,
        'seance' => null,
    ]);

    return $facture->fresh();
}

/**
 * Crée un tiers appartenant à une association donnée (bypass scope).
 */
function creerTiersAssociation(Association $association): Tiers
{
    $tiers = new Tiers;
    $tiers->forceFill([
        'association_id' => $association->id,
        'type' => 'structure',
        'nom' => 'Tiers Asso B',
        'pour_depenses' => false,
        'pour_recettes' => true,
        'est_helloasso' => false,
        'email_optout' => false,
    ]);
    $tiers->saveQuietly();

    return $tiers;
}

// ─── Setup : deux associations indépendantes ─────────────────────────────────

beforeEach(function (): void {
    // Association A (celle dont l'utilisateur est connecté)
    [$this->assocA, $this->userA] = creerAssociationAdmin();

    // Association B (la cible des tentatives d'intrusion)
    [$this->assocB, $this->userB] = creerAssociationAdmin();

    // Tiers et facture appartenant à Asso B
    $this->tiersB = creerTiersAssociation($this->assocB);
    $this->factureB = creerFactureValideeAssociation($this->assocB, $this->tiersB);

    // Boot le context sur Asso A (l'intrus)
    TenantContext::boot($this->assocA);
    session(['current_association_id' => $this->assocA->id]);
    $this->actingAs($this->userA);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Surface 1 : Liste de factures (FactureList) ────────────────────────────

describe('Surface 1 : FactureList — facture Asso B invisible depuis Asso A', function (): void {

    it('la facture validée de Asso B n\'apparaît pas dans la liste de Asso A', function (): void {
        // Crée aussi une facture valide pour Asso A (contrôle positif)
        $tiersA = creerTiersAssociation($this->assocA);
        $exercice = app(ExerciceService::class)->current();
        $factureA = new Facture([
            'numero' => 'F-'.$exercice.'-0001',
            'date' => now()->toDateString(),
            'statut' => StatutFacture::Validee,
            'tiers_id' => $tiersA->id,
            'montant_total' => 100.0,
            'exercice' => $exercice,
            'saisi_par' => $this->userA->id,
        ]);
        $factureA->association_id = $this->assocA->id;
        $factureA->saveQuietly();

        Livewire::test(FactureList::class)
            ->assertSee('F-'.$exercice.'-0001')
            ->assertDontSee('F-'.$exercice.'-9901');
    });

    it('le scope global TenantModel empêche Facture::all() de retourner les factures de Asso B', function (): void {
        // Sous le context Asso A, Facture::all() ne doit pas inclure la facture de Asso B
        $ids = Facture::pluck('id')->toArray();

        expect($ids)->not->toContain($this->factureB->id);
    });
});

// ─── Surface 2 : Recherche dans FactureList ──────────────────────────────────

describe('Surface 2 : Recherche — facture Asso B invisible via filterTiers', function (): void {

    it('filtrer par le nom du tiers de Asso B retourne 0 résultats pour Asso A', function (): void {
        Livewire::test(FactureList::class)
            ->set('filterTiers', 'Tiers Asso B')
            ->assertDontSee('F-'.app(ExerciceService::class)->current().'-9901')
            ->assertDontSee('Prestation Asso B');
    });

    it('Facture::where numero retourne null pour le numéro de la facture Asso B', function (): void {
        // Sous le scope de Asso A, find par l'ID de la facture Asso B retourne null
        $found = Facture::find($this->factureB->id);

        expect($found)->toBeNull();
    });
});

// ─── Surface 3 : Accès direct par ID HTTP ────────────────────────────────────

describe('Surface 3 : Accès direct HTTP — route model binding refuse la facture Asso B', function (): void {

    it('GET /facturation/factures/{id} avec l\'ID de Asso B retourne 404', function (): void {
        $response = $this->get(route('facturation.factures.show', $this->factureB->id));

        $response->assertStatus(404);
    });

    it('GET /facturation/factures/{id}/edit avec l\'ID de Asso B retourne 404', function (): void {
        $response = $this->get(route('facturation.factures.edit', $this->factureB->id));

        $response->assertStatus(404);
    });
});

// ─── Surface 4 : Vue 360° tiers ──────────────────────────────────────────────

describe('Surface 4 : Vue 360° tiers — le tiers de Asso B est inaccessible depuis Asso A', function (): void {

    it('GET /tiers/{id}/transactions avec le tiers de Asso B retourne 404', function (): void {
        $response = $this->get(route('tiers.transactions', $this->tiersB->id));

        $response->assertStatus(404);
    });

    it('Tiers::find avec l\'ID du tiers de Asso B retourne null sous le scope de Asso A', function (): void {
        $found = Tiers::find($this->tiersB->id);

        expect($found)->toBeNull();
    });
});

// ─── Surface 5 : Transaction générée par une facture libre de Asso B ─────────

describe('Surface 5 : Transaction Asso B invisible depuis Asso A', function (): void {

    it('une Transaction appartenant à Asso B est invisible via Transaction::find depuis Asso A', function (): void {
        // Crée une Transaction appartenant à Asso B
        $transaction = new Transaction([
            'type' => 'recette',
            'libelle' => 'Facture F-2026-9901',
            'montant_total' => 500.0,
            'date' => now()->toDateString(),
            'tiers_id' => $this->tiersB->id,
            'statut_reglement' => 'en_attente',
            'exercice' => app(ExerciceService::class)->current(),
        ]);
        $transaction->association_id = $this->assocB->id;
        $transaction->saveQuietly();

        // Sous le scope de Asso A, cette transaction doit être invisible
        $found = Transaction::find($transaction->id);

        expect($found)->toBeNull();
    });

    it('Transaction::count() sous Asso A ne comptabilise pas les transactions de Asso B', function (): void {
        // Crée une transaction pour Asso B
        $txB = new Transaction([
            'type' => 'recette',
            'libelle' => 'Facture Asso B',
            'montant_total' => 300.0,
            'date' => now()->toDateString(),
            'tiers_id' => $this->tiersB->id,
            'statut_reglement' => 'en_attente',
            'exercice' => app(ExerciceService::class)->current(),
        ]);
        $txB->association_id = $this->assocB->id;
        $txB->saveQuietly();

        // Crée une transaction pour Asso A (contrôle positif)
        $tiersA = creerTiersAssociation($this->assocA);
        $txA = new Transaction([
            'type' => 'recette',
            'libelle' => 'Facture Asso A',
            'montant_total' => 100.0,
            'date' => now()->toDateString(),
            'tiers_id' => $tiersA->id,
            'statut_reglement' => 'en_attente',
            'exercice' => app(ExerciceService::class)->current(),
        ]);
        $txA->association_id = $this->assocA->id;
        $txA->saveQuietly();

        // Sous le scope de Asso A, count = 1 (uniquement Asso A)
        expect(Transaction::count())->toBe(1);
    });
});

// ─── Surface 6 : Tentative de création cross-tenant via FactureService ────────

describe('Surface 6 : FactureService refuse de créer une facture avec un tiers cross-tenant', function (): void {

    it('creerLibreVierge avec le tiers de Asso B lève une exception', function (): void {
        expect(fn () => app(FactureService::class)->creerLibreVierge($this->tiersB->id))
            ->toThrow(RuntimeException::class);

        // Aucune facture créée pour Asso A
        expect(Facture::count())->toBe(0);
    });
});
