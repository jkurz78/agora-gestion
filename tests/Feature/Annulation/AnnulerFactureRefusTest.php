<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->association = Association::factory()->create();

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->association->update(['facture_compte_bancaire_id' => $this->compte->id]);

    $this->comptable = User::factory()->create();
    $this->comptable->associations()->attach($this->association->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $this->comptable->update(['derniere_association_id' => $this->association->id]);

    TenantContext::boot($this->association);

    $this->service = app(FactureService::class);
    $this->exerciceCourant = app(ExerciceService::class)->current();
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function refusCreerFactureValidee(Association $association, User $comptable, int $exercice): Facture
{
    $tiers = Tiers::factory()->create([
        'association_id' => $association->id,
        'pour_recettes' => true,
    ]);

    return Facture::create([
        'association_id' => $association->id,
        'numero' => sprintf('F-%d-0001', $exercice),
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => null,
        'montant_total' => 100.00,
        'saisi_par' => $comptable->id,
        'exercice' => $exercice,
    ]);
}

// ─── BDD §2 Scénario #8 : refus double annulation ────────────────────────────

/**
 * Vérifie que tenter d'annuler une facture déjà au statut Annulee lève
 * RuntimeException "Cette facture est déjà annulée." (assertNotAnnulee S2).
 */
test('il refuse double annulation', function (): void {
    $this->actingAs($this->comptable);

    $facture = refusCreerFactureValidee(
        $this->association,
        $this->comptable,
        $this->exerciceCourant,
    );

    // Première annulation — doit réussir
    $this->service->annuler($facture);

    $facture->refresh();
    expect($facture->statut)->toBe(StatutFacture::Annulee);

    // Deuxième tentative — doit échouer avec un message dédié
    expect(fn () => $this->service->annuler($facture))
        ->toThrow(RuntimeException::class, 'déjà annulée');
});

// ─── BDD §2 Scénario #9 : refus annulation d'une facture brouillon ───────────

/**
 * Non-régression : le check "Seule une facture validée peut être annulée."
 * doit continuer à fonctionner pour les factures brouillon (comportement inchangé S2).
 */
test('il refuse annulation d\'une facture brouillon', function (): void {
    $this->actingAs($this->comptable);

    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $facture = Facture::create([
        'association_id' => $this->association->id,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $tiers->id,
        'montant_total' => 0,
        'saisi_par' => $this->comptable->id,
        'exercice' => $this->exerciceCourant,
    ]);

    expect(fn () => $this->service->annuler($facture))
        ->toThrow(RuntimeException::class, 'Seule une facture validée peut être annulée.');
});

// ─── BDD §2 Scénario #11 : refus via Gate::authorize pour Gestionnaire ───────

/**
 * Un Gestionnaire ne peut pas annuler une facture via le service direct.
 * Gate::authorize('annuler', $facture) lève AuthorizationException.
 *
 * Note : le bouton "Annuler la facture" n'est pas affiché en UI pour ce rôle
 * (testé séparément via AnnulerFacturePolicyTest). Ce test vérifie la défense
 * en profondeur — même si un Gestionnaire appelle le service directement,
 * le Gate bloque.
 */
test('il refuse annulation pour utilisateur Gestionnaire', function (): void {
    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->association->id, [
        'role' => RoleAssociation::Gestionnaire->value,
        'joined_at' => now(),
    ]);
    $gestionnaire->update(['derniere_association_id' => $this->association->id]);

    $this->actingAs($gestionnaire);

    $facture = refusCreerFactureValidee(
        $this->association,
        $this->comptable,
        $this->exerciceCourant,
    );

    expect(fn () => $this->service->annuler($facture))
        ->toThrow(AuthorizationException::class);
});
