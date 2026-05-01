<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Crée une association avec un comptable et retourne [asso, comptable, compte].
 *
 * @return array{Association, User, CompteBancaire}
 */
function mtCreerAssociation(): array
{
    $asso = Association::factory()->create();
    $compte = CompteBancaire::factory()->create(['association_id' => $asso->id]);
    $asso->update(['facture_compte_bancaire_id' => $compte->id]);

    $comptable = User::factory()->create();
    $comptable->associations()->attach($asso->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $comptable->update(['derniere_association_id' => $asso->id]);

    return [$asso, $comptable, $compte];
}

/**
 * Crée une facture validée appartenant à une association donnée (bypass scope).
 */
function mtCreerFactureValidee(Association $asso, Tiers $tiers, int $exercice): Facture
{
    $facture = new Facture([
        'numero' => 'F-'.$exercice.'-9901',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 100.0,
        'exercice' => $exercice,
        'mode_paiement_prevu' => ModePaiement::Virement->value,
        'saisi_par' => 1,
    ]);
    $facture->association_id = $asso->id;
    $facture->saveQuietly();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Prestation MT',
        'prix_unitaire' => 100.0,
        'quantite' => 1.0,
        'montant' => 100.0,
        'ordre' => 1,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => null,
        'operation_id' => null,
        'seance' => null,
    ]);

    return $facture->fresh();
}

/**
 * Crée un Tiers appartenant à une association (bypass scope).
 */
function mtCreerTiers(Association $asso): Tiers
{
    $tiers = new Tiers;
    $tiers->forceFill([
        'association_id' => $asso->id,
        'type' => 'structure',
        'nom' => 'Tiers '.$asso->id,
        'pour_depenses' => false,
        'pour_recettes' => true,
        'est_helloasso' => false,
        'email_optout' => false,
    ]);
    $tiers->saveQuietly();

    return $tiers;
}

// ─── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    // Association A et B
    [$this->assoA, $this->comptableA, $this->compteA] = mtCreerAssociation();
    [$this->assoB, $this->comptableB, $this->compteB] = mtCreerAssociation();

    $exercice = app(ExerciceService::class)->current();

    // Tiers et facture appartenant à Asso B (créés en boot B)
    TenantContext::boot($this->assoB);
    $this->tiersB = mtCreerTiers($this->assoB);
    $this->factureB = mtCreerFactureValidee($this->assoB, $this->tiersB, $exercice);
    TenantContext::clear();

    // Contexte courant = Asso A (l'intrus)
    TenantContext::boot($this->assoA);
    $this->actingAs($this->comptableA);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── BDD §2 #12 : multi-tenant — scope global TenantModel ───────────────────

test('un comptable de asso A ne peut pas fetcher la facture du tenant B via Facture find', function (): void {
    // Sous le scope d'Asso A, find() de la facture de B doit retourner null (fail-closed)
    $found = Facture::find($this->factureB->id);

    expect($found)->toBeNull();

    // Et donc : il est impossible d'appeler annuler() sur une facture qu'on ne peut pas fetcher
    // (on ne peut même pas obtenir l'objet)
    expect(Facture::where('id', $this->factureB->id)->count())->toBe(0);
});

// ─── BDD §2 #12 : injection directe via service → assertTenantOwnership ───────

test('injection directe d une facture d un autre tenant via le service est rejetee par assertTenantOwnership', function (): void {
    // Fetch la facture de B dans le bon contexte (simule un objet en mémoire)
    TenantContext::clear();
    TenantContext::boot($this->assoB);
    $factureBEnMemoire = Facture::find($this->factureB->id);
    expect($factureBEnMemoire)->not->toBeNull();
    TenantContext::clear();

    // Re-boote sur Asso A
    TenantContext::boot($this->assoA);
    $this->actingAs($this->comptableA);

    // Appel direct du service avec l'objet de B — doit lever RuntimeException
    expect(fn () => app(FactureService::class)->annuler($factureBEnMemoire))
        ->toThrow(RuntimeException::class, "Accès interdit : cette facture n'appartient pas à votre association.");
});
