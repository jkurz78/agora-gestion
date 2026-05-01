<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Livewire\FactureShow;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Crée une facture manuelle avec 1 ligne MontantManuel et la valide.
 * Retourne [facture rafraîchie, Tg].
 */
function modalCreerFactureAvecMontantManuel(
    FactureService $service,
    Tiers $tiers,
    SousCategorie $sousCategorie,
    float $montant = 80.0,
): array {
    $facture = $service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Cotisation stage',
        'prix_unitaire' => $montant,
        'quantite' => 1.0,
        'montant' => $montant,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => $montant]);
    $facture->refresh();

    $service->valider($facture);
    $facture->refresh();

    /** @var Transaction $tg */
    $tg = Transaction::latest('id')->first();

    return [$facture, $tg];
}

/**
 * Crée une transaction préexistante (pour lignes ref) et une facture validée la référençant.
 * Retourne [facture rafraîchie, Tref].
 */
function modalCreerFactureAvecTransactionRef(
    Association $association,
    User $comptable,
    CompteBancaire $compte,
    Tiers $tiers,
    int $exercice,
    float $montant = 50.0,
): array {
    // Créer une TX préexistante (simulant un règlement reçu)
    $tref = Transaction::create([
        'association_id' => $association->id,
        'type' => TypeTransaction::Recette->value,
        'libelle' => 'Règlement référencé Tref',
        'montant_total' => $montant,
        'date' => now()->toDateString(),
        'statut_reglement' => StatutReglement::Recu->value,
        'compte_id' => $compte->id,
        'tiers_id' => $tiers->id,
        'exercice' => $exercice,
        'saisi_par' => $comptable->id,
    ]);

    // Créer la facture validée
    $facture = Facture::create([
        'association_id' => $association->id,
        'numero' => sprintf('F-%d-TEST', $exercice),
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => $montant,
        'saisi_par' => $comptable->id,
        'exercice' => $exercice,
    ]);

    // Attacher Tref au pivot
    $facture->transactions()->attach($tref->id);

    return [$facture, $tref];
}

// ─── Test 1 : la modale liste les transactions générées par lignes manuelles ─

test('la modale liste les transactions generees par lignes manuelles', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg] = modalCreerFactureAvecMontantManuel(
        $this->service,
        $tiers,
        $sousCategorie,
        80.0,
    );

    $component = Livewire::test(FactureShow::class, ['facture' => $facture]);

    // La vue doit exposer transactionsGenereesParLignesManuelles contenant Tg
    $component->assertViewHas(
        'transactionsGenereesParLignesManuelles',
        fn ($collection) => $collection->contains(fn ($tx) => (int) $tx->id === (int) $tg->id)
    );

    // Le libellé de Tg doit être visible dans la section "annulation comptable forcée"
    $component->assertSee('annulation comptable forc');
    $component->assertSee($tg->libelle);
});

// ─── Test 2 : la modale liste les règlements référencés ──────────────────────

test('la modale liste les reglements references', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    [$facture, $tref] = modalCreerFactureAvecTransactionRef(
        $this->association,
        $this->comptable,
        $this->compte,
        $tiers,
        $this->exerciceCourant,
        50.0,
    );

    $component = Livewire::test(FactureShow::class, ['facture' => $facture]);

    // La vue doit exposer transactionsReferencees contenant Tref
    $component->assertViewHas(
        'transactionsReferencees',
        fn ($collection) => $collection->contains(fn ($tx) => (int) $tx->id === (int) $tref->id)
    );

    // Le texte explicatif "Pour rembourser" doit être affiché
    $component->assertSee('Pour rembourser un r');
    $component->assertSee($tref->libelle);
});

// ─── Test 3 : le bandeau banque s'affiche si au moins une TX MM est Pointée ──

test('le bandeau banque s affiche si au moins une MM est Pointee', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg] = modalCreerFactureAvecMontantManuel(
        $this->service,
        $tiers,
        $sousCategorie,
        150.0,
    );

    // Forcer Tg en Pointe rattachée à un rapprochement verrouillé
    $r1 = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
    ]);

    $tg->update([
        'rapprochement_id' => $r1->id,
        'statut_reglement' => StatutReglement::Pointe->value,
    ]);

    $component = Livewire::test(FactureShow::class, ['facture' => $facture]);

    // La vue doit exposer aTransactionMMPointee = true
    $component->assertViewHas('aTransactionMMPointee', true);

    // Le bandeau orange doit être présent
    $component->assertSee('rapprochement bancaire', false);
});

// ─── Test 4 : le bandeau banque ne s'affiche pas si toutes les MM sont EnAttente

test('le bandeau banque ne s affiche pas si toutes les MM sont EnAttente', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg] = modalCreerFactureAvecMontantManuel(
        $this->service,
        $tiers,
        $sousCategorie,
        80.0,
    );

    // Tg reste EnAttente (pas de rapprochement)
    expect($tg->statut_reglement)->toBe(StatutReglement::EnAttente);

    $component = Livewire::test(FactureShow::class, ['facture' => $facture]);

    // La vue doit exposer aTransactionMMPointee = false
    $component->assertViewHas('aTransactionMMPointee', false);

    // Le bandeau orange NE doit PAS être présent
    $component->assertDontSee('extourne d\'au moins une transaction devra être point');
});

// ─── Test 5 : le bouton "Annuler la facture" n'est pas affiché pour un Gestionnaire ─

test('le bouton annuler la facture n est pas affiche pour un Gestionnaire', function (): void {
    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->association->id, [
        'role' => RoleAssociation::Gestionnaire->value,
        'joined_at' => now(),
    ]);
    $gestionnaire->update(['derniere_association_id' => $this->association->id]);

    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $facture = Facture::create([
        'association_id' => $this->association->id,
        'numero' => 'F-2026-0001',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 100.00,
        'saisi_par' => $this->comptable->id,
        'exercice' => $this->exerciceCourant,
    ]);

    $this->actingAs($gestionnaire);

    $component = Livewire::test(FactureShow::class, ['facture' => $facture]);

    // La vue doit exposer peutAnnuler = false
    $component->assertViewHas('peutAnnuler', false);

    // Le bouton qui ouvre la modale d'annulation ne doit pas être visible
    $component->assertDontSee('annulationModal');
});

// ─── Test 6 : le bouton "Annuler la facture" est affiché pour un Comptable ───

test('le bouton annuler la facture est affiche pour un Comptable', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $facture = Facture::create([
        'association_id' => $this->association->id,
        'numero' => 'F-2026-0002',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 100.00,
        'saisi_par' => $this->comptable->id,
        'exercice' => $this->exerciceCourant,
    ]);

    // comptable déjà authentifié dans beforeEach
    $component = Livewire::test(FactureShow::class, ['facture' => $facture]);

    // La vue doit exposer peutAnnuler = true
    $component->assertViewHas('peutAnnuler', true);

    // Le bouton qui ouvre la modale d'annulation doit être visible
    $component->assertSee('annulationModal');
});
