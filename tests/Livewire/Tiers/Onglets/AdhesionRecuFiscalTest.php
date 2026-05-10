<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Livewire\Tiers\Onglets\Adhesion as AdhesionComponent;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Marie Curie',
        'signataire_qualite' => 'Présidente',
    ]);
    TenantContext::boot($this->asso);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->asso->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->actingAs($this->user);
});

/**
 * Crée une adhésion payée + déductible avec ligne cotisation valide.
 */
function creerAdhesionDeductiblePayee(array $tiersOverrides = [], array $adhesionOverrides = []): Adhesion
{
    $tiers = Tiers::factory()->create(array_merge([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'Recu',
        'adresse_ligne1' => '5 avenue de la République',
        'code_postal' => '69001',
        'ville' => 'Lyon',
    ], $tiersOverrides));

    $sousCat = SousCategorie::query()
        ->whereHas('usages', fn ($q) => $q->where('usage', UsageComptable::Cotisation->value))
        ->first()
        ?? SousCategorie::factory()->pourCotisations()->create();

    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
        'date' => now()->subMonths(1),
    ]);

    // Supprimer les lignes auto-créées par les observers
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 75.00,
    ]);

    // Supprimer les adhésions auto-créées par les observers
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    return Adhesion::factory()->create(array_merge([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => mt_rand(2020, 2099),
    ], $adhesionOverrides));
}

// ── Cas 1 : adhésion déductible + asso éligible → bouton "Émettre" affiché ──
it('affiche le bouton "Émettre" pour une adhésion déductible avec asso éligible', function () {
    $adhesion = creerAdhesionDeductiblePayee();

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertSee('Émettre');
});

// ── Cas 2 : adhésion non déductible → bouton non affiché ──
it('n\'affiche pas le bouton "Émettre" pour une adhésion non déductible', function () {
    $adhesion = creerAdhesionDeductiblePayee([], ['deductible_fiscal' => false]);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertDontSee('Émettre');
});

// ── Cas 3 : adhésion gratuite → bouton non affiché ──
it('n\'affiche pas le bouton "Émettre" pour une adhésion gratuite', function () {
    $tiers = Tiers::factory()->create();

    // Supprimer les adhésions auto-créées
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_id' => null,
        'deductible_fiscal' => true,
        'exercice' => 2099,
    ]);

    Livewire::test(AdhesionComponent::class, ['tiers' => $tiers])
        ->assertDontSee('Émettre');
});

// ── Cas 4 : asso non éligible → bouton non affiché (même si adhésion déductible) ──
it('n\'affiche pas le bouton "Émettre" si l\'asso n\'est pas éligible', function () {
    // Désactiver l'éligibilité de l'asso
    $this->asso->update(['eligible_recu_fiscal' => false]);

    $adhesion = creerAdhesionDeductiblePayee();

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertDontSee('Émettre');
});

// ── Cas 5 : reçu déjà émis → badge n° affiché, pas de bouton "Émettre" ──
it('affiche le badge n° reçu et pas le bouton "Émettre" quand un reçu est déjà émis', function () {
    $adhesion = creerAdhesionDeductiblePayee();

    // Émettre le reçu via le service
    $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->assertSee($recu->numero)
        ->assertDontSee('Émettre');
});

// ── Cas 6 : click "Émettre" → reçu créé + redirect vers download ──
it('click sur "Émettre" crée le reçu et redirige vers le téléchargement', function () {
    $adhesion = creerAdhesionDeductiblePayee();

    expect(RecuFiscalEmis::count())->toBe(0);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->call('emettreRecuFiscalAdhesion', $adhesion->id)
        ->assertRedirect();

    expect(RecuFiscalEmis::count())->toBe(1);
    $recu = RecuFiscalEmis::first();
    expect($recu->annule_at)->toBeNull();
});

// ══════════════════════════════════════════════════════
// Phase 6 — Modale d'avertissement HelloAsso
// ══════════════════════════════════════════════════════

/**
 * Crée une adhésion payée + déductible liée à une formule HelloAsso.
 */
function creerAdhesionHelloAsso(): Adhesion
{
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'HelloAsso',
        'adresse_ligne1' => '10 rue de Rivoli',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $sousCat = SousCategorie::query()
        ->whereHas('usages', fn ($q) => $q->where('usage', UsageComptable::Cotisation->value))
        ->first()
        ?? SousCategorie::factory()->pourCotisations()->create();

    $formule = FormuleAdhesion::factory()->helloasso('form-slug-test', 42)->create([
        'sous_categorie_id' => $sousCat->id,
        'deductible_fiscal' => true,
    ]);

    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Virement,
        'date' => now()->subMonths(1),
    ]);

    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
    ]);

    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    return Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'formule_adhesion_id' => $formule->id,
        'deductible_fiscal' => true,
        'exercice' => mt_rand(2031, 2099),
    ]);
}

// ── Phase 6, Cas 1 : click "Émettre" sur adhésion HelloAsso → modale affichée, aucun reçu créé ──
it('affiche la modale d\'avertissement HelloAsso sans créer de reçu', function () {
    $adhesion = creerAdhesionHelloAsso();

    expect(RecuFiscalEmis::count())->toBe(0);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->call('emettreRecuFiscalAdhesion', $adhesion->id)
        ->assertSet('showHelloAssoWarning', true)
        ->assertSet('pendingAdhesionId', $adhesion->id)
        ->assertNoRedirect();

    expect(RecuFiscalEmis::count())->toBe(0);
});

// ── Phase 6, Cas 2 : confirmEmettreRecuApresAvertissement → reçu créé + redirect ──
it('crée le reçu et redirige après confirmation de la modale HelloAsso', function () {
    $adhesion = creerAdhesionHelloAsso();

    expect(RecuFiscalEmis::count())->toBe(0);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->set('pendingAdhesionId', $adhesion->id)
        ->set('showHelloAssoWarning', true)
        ->call('confirmEmettreRecuApresAvertissement')
        ->assertRedirect();

    expect(RecuFiscalEmis::count())->toBe(1);
    expect(RecuFiscalEmis::first()->annule_at)->toBeNull();
});

// ── Phase 6, Cas 3 : cancelEmettreRecuApresAvertissement → modale fermée, aucun reçu créé ──
it('ferme la modale sans créer de reçu après annulation', function () {
    $adhesion = creerAdhesionHelloAsso();

    expect(RecuFiscalEmis::count())->toBe(0);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->set('pendingAdhesionId', $adhesion->id)
        ->set('showHelloAssoWarning', true)
        ->call('cancelEmettreRecuApresAvertissement')
        ->assertSet('showHelloAssoWarning', false)
        ->assertSet('pendingAdhesionId', null)
        ->assertNoRedirect();

    expect(RecuFiscalEmis::count())->toBe(0);
});

// ── Phase 6, Cas 4 : adhésion manuelle → pas de modale, génération directe ──
it('génère directement le reçu sans modale pour une adhésion manuelle (est_helloasso=false)', function () {
    $adhesion = creerAdhesionDeductiblePayee();

    expect(RecuFiscalEmis::count())->toBe(0);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->call('emettreRecuFiscalAdhesion', $adhesion->id)
        ->assertSet('showHelloAssoWarning', false)
        ->assertSet('pendingAdhesionId', null)
        ->assertRedirect();

    expect(RecuFiscalEmis::count())->toBe(1);
});

// ── UX erreur : adresse incomplète → propriété recuFiscalError, pas d'exception 500 ──
it('expose un message d\'erreur plutôt qu\'une exception quand l\'adresse du donateur est incomplète', function () {
    // Adhésion déductible mais tiers sans adresse_ligne1 → RecuFiscalException
    // doit être catchée et exposée via $recuFiscalError (propriété publique).
    $adhesion = creerAdhesionDeductiblePayee(['adresse_ligne1' => null]);

    expect(RecuFiscalEmis::count())->toBe(0);

    Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->call('emettreRecuFiscalAdhesion', $adhesion->id)
        ->assertNoRedirect()
        ->assertSet('recuFiscalError', fn ($msg) => is_string($msg) && str_contains($msg, 'Adresse'));

    expect(RecuFiscalEmis::count())->toBe(0);
});

it('réinitialise recuFiscalError au prochain clic Émettre réussi', function () {
    // Première tentative : adresse manquante → erreur exposée
    $adhesion = creerAdhesionDeductiblePayee(['adresse_ligne1' => null]);

    $component = Livewire::test(AdhesionComponent::class, ['tiers' => $adhesion->tiers])
        ->call('emettreRecuFiscalAdhesion', $adhesion->id)
        ->assertSet('recuFiscalError', fn ($msg) => is_string($msg) && str_contains($msg, 'Adresse'));

    // Compléter l'adresse puis réessayer → reçu généré, error remis à null
    $adhesion->tiers->update(['adresse_ligne1' => '5 avenue de la République']);

    $component->call('emettreRecuFiscalAdhesion', $adhesion->id)
        ->assertSet('recuFiscalError', null)
        ->assertRedirect();

    expect(RecuFiscalEmis::count())->toBe(1);
});

it('dismissRecuFiscalError efface le message', function () {
    Livewire::test(AdhesionComponent::class, ['tiers' => Tiers::factory()->create()])
        ->set('recuFiscalError', 'un message d\'erreur')
        ->call('dismissRecuFiscalError')
        ->assertSet('recuFiscalError', null);
});
