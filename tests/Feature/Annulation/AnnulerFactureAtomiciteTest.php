<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Events\TransactionExtournee;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
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
    $this->actingAs($this->comptable);

    $this->service = app(FactureService::class);
    $this->exerciceCourant = app(ExerciceService::class)->current();
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Helper : crée une facture mixte (1 MM + 1 ref) validée ─────────────────

/**
 * Crée une facture brouillon avec :
 *   - 1 ligne MontantManuel "Stage avril" 100 € (sous-catégorie recette donnée)
 *   - 1 TX recette préexistante Tref 50 € Recu rattachée via ajouterTransactions
 * La valide (ce qui génère Tg EnAttente pour la ligne MM).
 * Retourne [facture rafraîchie, Tg, Tref].
 *
 * @return array{Facture, Transaction, Transaction}
 */
function atomiciteCreerFactureValidee(
    FactureService $service,
    Tiers $tiers,
    SousCategorie $sousCategorie,
    CompteBancaire $compte,
): array {
    // Tref préexistante 50 € Recu
    $tref = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Paiement ref préexistant',
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Virement,
        'statut_reglement' => StatutReglement::Recu,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tref->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
    ]);

    // Facture brouillon vierge
    $facture = $service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    // Ligne MontantManuel 100 €
    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Stage avril',
        'prix_unitaire' => 100.0,
        'quantite' => 1.0,
        'montant' => 100.0,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => 150.0]);
    $facture->refresh();

    // Rattacher Tref via ajouterTransactions (ajoute la ligne de type Montant)
    $service->ajouterTransactions($facture, [$tref->id]);
    $facture->refresh();

    // Valider → génère Tg pour la ligne MM
    $service->valider($facture);
    $facture->refresh();

    // Tg est la dernière TX créée (générée par valider)
    $tg = Transaction::where('id', '!=', $tref->id)
        ->orderByDesc('id')
        ->first();

    return [$facture, $tg, $tref];
}

// ─── AC-11 : atomicité — exception dans la transaction → rollback complet ────

test('exception pendant annulation provoque rollback complet', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg, $tref] = atomiciteCreerFactureValidee(
        $this->service,
        $tiers,
        $sousCategorie,
        $this->compte,
    );

    // ── Préconditions ─────────────────────────────────────────────────────────

    expect($facture->statut)->toBe(StatutFacture::Validee);
    expect($tg->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tg->extournee_at)->toBeNull();
    expect($tref->extournee_at)->toBeNull();

    // Pivot : Tg ET Tref présentes avant annulation
    $txAvant = $facture->fresh()->transactions;
    expect($txAvant->contains(fn ($t) => (int) $t->id === (int) $tg->id))->toBeTrue();
    expect($txAvant->contains(fn ($t) => (int) $t->id === (int) $tref->id))->toBeTrue();

    // ── Forcer un rollback via Event::listen sur TransactionExtournee ─────────
    // L'event est dispatché par TransactionExtourneService::extourner() DANS la
    // DB::transaction de FactureService::annuler(). Throw ici → rollback Laravel.

    Event::listen(TransactionExtournee::class, function (): void {
        throw new RuntimeException('forcer rollback');
    });

    expect(fn () => $this->service->annuler($facture))
        ->toThrow(RuntimeException::class, 'forcer rollback');

    // ── Assertions post-exception : tout doit être comme avant ───────────────

    // Facture : toujours Validee, pas d'avoir, pas de date_annulation
    $factureFraiche = $facture->fresh();
    expect($factureFraiche->statut)->toBe(StatutFacture::Validee);
    expect($factureFraiche->numero_avoir)->toBeNull();
    expect($factureFraiche->date_annulation)->toBeNull();

    // Aucune extourne en base
    expect(Extourne::count())->toBe(0);

    // Aucun lettrage
    expect(
        RapprochementBancaire::where('type', TypeRapprochement::Lettrage)->count()
    )->toBe(0);

    // Tg : extournee_at null (rollback du flag)
    $tgFrais = $tg->fresh();
    expect($tgFrais->extournee_at)->toBeNull();

    // Pivot intact : Tg ET Tref encore attachées à la facture
    $txApres = $factureFraiche->transactions;
    expect($txApres->contains(fn ($t) => (int) $t->id === (int) $tg->id))->toBeTrue();
    expect($txApres->contains(fn ($t) => (int) $t->id === (int) $tref->id))->toBeTrue();
});
