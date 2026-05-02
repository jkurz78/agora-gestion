<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

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

// ─── Helper : crée une facture mixte validée (1 MM + 1 ref) ──────────────────

/**
 * Crée une facture mixte :
 *   - 1 ligne MontantManuel 100 € (génère Tg à la validation)
 *   - 1 TX recette préexistante Tref 50 € Recu référencée via ajouterTransactions
 * La valide. Retourne [facture, Tg, Tref].
 *
 * @return array{Facture, Transaction, Transaction}
 */
function loggingCreerFactureMixteValidee(
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

    // Rattacher Tref via ajouterTransactions
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

// ─── AC-19 : log enrichi facture.annulee ─────────────────────────────────────

test('annuler dispatche un log facture.annulee avec les IDs enrichis', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg, $tref] = loggingCreerFactureMixteValidee(
        $this->service,
        $tiers,
        $sousCategorie,
        $this->compte,
    );

    // ── Spy sur Log ───────────────────────────────────────────────────────────
    Log::spy();

    // ── Action ────────────────────────────────────────────────────────────────
    $this->service->annuler($facture);

    $factureFraiche = $facture->fresh();

    // ── Assert : log 'facture.annulee' dispatché avec le bon payload ──────────
    Log::shouldHaveReceived('info')
        ->with('facture.annulee', Mockery::on(function (array $context) use ($facture, $tg, $tref, $factureFraiche): bool {
            return (int) $context['facture_id'] === (int) $facture->id
                && $context['numero_avoir'] === $factureFraiche->numero_avoir
                && is_array($context['transactions_extournees'])
                && in_array((int) $tg->id, $context['transactions_extournees'], strict: true)
                && is_array($context['transactions_detachees'])
                && in_array((int) $tref->id, $context['transactions_detachees'], strict: true);
        }))
        ->once();

    // Le log doit porter au minimum les 4 clés métier
    // (association_id + user_id sont injectés automatiquement par LogContext::boot
    // dans le middleware BootTenantConfig — pas dans le payload explicite du Log::info)
    expect($factureFraiche->numero_avoir)->toStartWith('AV-');
});
