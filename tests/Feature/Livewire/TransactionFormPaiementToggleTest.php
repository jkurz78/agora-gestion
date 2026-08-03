<?php

declare(strict_types=1);

/**
 * Le bascule « Paiement effectué ? » ne doit jamais mentir.
 *
 * Constaté en recette le 2026-08-03 sur la dépense 2025-2026:00179 : après
 * l'annulation d'un règlement, le formulaire affichait « En attente de
 * règlement » ET le bascule sur « Oui ». L'utilisateur a donc basculé sur
 * « Non » puis validé — sans effet, `statut_reglement` n'étant jamais écrit
 * lors d'une modification. Il en a conclu que l'annulation avait échoué, alors
 * qu'elle avait réussi.
 *
 * Deux exigences en découlent :
 *   1. l'état du bascule suit l'état réel du règlement, y compris après son
 *      annulation ;
 *   2. le bascule n'est proposé que lorsqu'il peut réellement agir.
 */

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create(['exercice_mois_debut' => 9]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();
    $this->compteBancaire = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->compteDepense = Compte::factory()->numero('606')->create();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

/** Une dépense comptant : T1 au journal d'achat, réglée par sa T2. */
function depenseRegleeToggle(object $ctx): Transaction
{
    $range = app(ExerciceService::class)->dateRange(app(ExerciceService::class)->current());

    Livewire::test(TransactionForm::class)
        ->set('type', 'depense')
        ->set('date', $range['start']->addDays(10)->toDateString())
        ->set('dateReglement', $range['start']->addDays(12)->toDateString())
        ->set('libelle', 'Prestataire — doublon')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $ctx->compteBancaire->id)
        ->set('tiers_id', $ctx->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $ctx->compteDepense->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '750',
            'notes' => '',
        ]])
        ->call('save');

    return Transaction::where('libelle', 'Prestataire — doublon')->sole();
}

it('remet le bascule en cohérence après l’annulation du règlement', function (): void {
    $depense = depenseRegleeToggle($this);

    $reglement = app(PostesTiersOuvertsService::class)->reglements($depense)->sole();

    $form = Livewire::test(TransactionForm::class)
        ->dispatch('edit-transaction', id: (int) $depense->id);

    // État initial : la dépense est réglée, le bascule le dit.
    $form->assertSet('paiementRecu', true)
        ->assertSet('isLockedByReglement', true);

    // L'utilisateur annule le règlement depuis la modale dédiée.
    app(PosteTiersReglementService::class)->annuler($reglement->transactionId);
    $form->call('rafraichirEtatReglement');

    // Le formulaire doit se lire d'une seule voix : plus de règlement, donc
    // plus de « paiement effectué ». C'est la contradiction du 2026-08-03.
    $form->assertSet('isLockedByReglement', false)
        ->assertSet('etatPaiement', 'ouvert')
        ->assertSet('paiementRecu', false);
});

it('ne ressuscite pas le règlement annulé quand on enregistre la fiche', function (): void {
    // Recette du 2026-08-03 : après « Annuler le règlement », valider par
    // « Mettre à jour » recréait le règlement — il fallait fermer par « Annuler »
    // pour que l'annulation tienne. La mise à jour détruit et régénère les
    // écritures, et `enrichirPartieDouble` déduit « comptant » de mode_paiement,
    // resté sur la transaction alors que le paiement n'existait plus.
    $depense = depenseRegleeToggle($this);
    $reglement = app(PostesTiersOuvertsService::class)->reglements($depense)->sole();

    // Forme héritée du backfill : la T1 porte son mode de paiement, là où une
    // transaction créée dans V5 le laisse à la T2. C'est celle de la dépense
    // 2025-2026:00179, et la seule où le défaut se manifeste.
    $depense->forceFill(['mode_paiement' => ModePaiement::Virement->value])->save();

    app(PosteTiersReglementService::class)->annuler($reglement->transactionId);

    // Plus de paiement, donc plus de mode de paiement : la dépense est une dette ouverte.
    expect($depense->fresh()->mode_paiement)->toBeNull();

    Livewire::test(TransactionForm::class)
        ->dispatch('edit-transaction', id: (int) $depense->id)
        ->call('save');

    expect(app(PostesTiersOuvertsService::class)->reglements($depense->fresh())->count())->toBe(0)
        ->and($depense->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('ne propose pas le bascule quand la mise à jour ne l’honorerait pas', function (): void {
    $depense = depenseRegleeToggle($this);

    // Réglée et portant son mode de paiement : la mise à jour n'écrit jamais
    // `statut_reglement` sur une transaction existante, le bascule serait un
    // bouton sans effet.
    Livewire::test(TransactionForm::class)
        ->dispatch('edit-transaction', id: (int) $depense->id)
        ->assertSet('paiementModifiable', false);
});

it('propose le bascule sur une dette ouverte, où il agit réellement', function (): void {
    $range = app(ExerciceService::class)->dateRange(app(ExerciceService::class)->current());

    Livewire::test(TransactionForm::class)
        ->set('type', 'depense')
        ->set('date', $range['start']->addDays(10)->toDateString())
        ->set('libelle', 'Dette ouverte')
        ->set('paiementRecu', false)
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteDepense->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '300',
            'notes' => '',
        ]])
        ->call('save');

    $dette = Transaction::where('libelle', 'Dette ouverte')->sole();

    expect($dette->statut_reglement)->toBe(StatutReglement::EnAttente)
        ->and($dette->mode_paiement)->toBeNull();

    Livewire::test(TransactionForm::class)
        ->dispatch('edit-transaction', id: (int) $dette->id)
        ->assertSet('paiementRecu', false)
        ->assertSet('paiementModifiable', true);
});
