<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Enums\TypeCategorie;
use App\Livewire\BackOffice\NoteDeFrais\Show as BackOfficeShow;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Helpers de setup (scope fichier)
// ---------------------------------------------------------------------------

/**
 * Crée une Association + boote le TenantContext.
 */
function e2eCreateAsso(): Association
{
    $asso = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($asso);
    session(['current_association_id' => $asso->id]);

    return $asso;
}

/**
 * Crée un Tiers (Jean) et le connecte sur le guard tiers-portail.
 */
function e2eCreateJean(Association $asso): Tiers
{
    $jean = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($jean);

    return $jean;
}

/**
 * Crée un User comptable (Admin) pour le back-office.
 */
function e2eCreateComptable(Association $asso): User
{
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $asso->id]);

    return $user;
}

/**
 * Crée la sous-catégorie Dépense + sous-catégorie AbandonCreance (Recette).
 *
 * @return array{scDepense: SousCategorie, scAbandon: SousCategorie}
 */
function e2eCreateCategories(Association $asso): array
{
    $catDepense = Categorie::factory()->create([
        'association_id' => $asso->id,
        'type' => TypeCategorie::Depense->value,
    ]);
    $scDepense = SousCategorie::factory()->create([
        'association_id' => $asso->id,
        'categorie_id' => $catDepense->id,
        'nom' => 'Frais divers E2E',
    ]);

    $catRecette = Categorie::factory()->create([
        'association_id' => $asso->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    $scAbandon = SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $asso->id,
        'categorie_id' => $catRecette->id,
        'nom' => '771 Abandon de créance E2E',
    ]);

    return ['scDepense' => $scDepense, 'scAbandon' => $scAbandon];
}

/**
 * Crée un CompteBancaire actif.
 */
function e2eCreateCompte(Association $asso): CompteBancaire
{
    return CompteBancaire::factory()->create([
        'association_id' => $asso->id,
        'actif_recettes_depenses' => true,
    ]);
}

// ---------------------------------------------------------------------------
// Scénario E2E
// ---------------------------------------------------------------------------

it('Jean soumet une NDF avec abandon, le comptable constate, Jean voit le statut final', function (): void {
    Storage::fake('local');

    // ── Setup ──────────────────────────────────────────────────────────────
    $asso = e2eCreateAsso();
    $jean = e2eCreateJean($asso);
    $categories = e2eCreateCategories($asso);
    $scDepense = $categories['scDepense'];
    $compte = e2eCreateCompte($asso);
    $comptable = e2eCreateComptable($asso);

    $dateNdf = '2025-10-15';
    $assoId = (int) $asso->id;

    // ── Étape 1 : Jean soumet une NDF avec abandon_creance_propose = true ──

    // Créer un brouillon existant avec une ligne + PJ (le Form component exige
    // une PJ pour submit — on passe par le composant directement, comme dans
    // FormSubmitTest et FormAbandonCreanceTest).
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $asso->id,
        'tiers_id' => $jean->id,
        'libelle' => 'Frais trimestriels E2E',
        'date' => $dateNdf,
        'abandon_creance_propose' => false,
    ]);

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $scDepense->id,
        'libelle' => 'Déplacement mission',
        'montant' => 120.00,
        'piece_jointe_path' => "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    Storage::disk('local')->put(
        "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
        'fake pdf content'
    );

    // Reboot contexte pour la session portail
    TenantContext::boot($asso);
    Auth::guard('tiers-portail')->login($jean);

    $formComponent = new Form;
    $formComponent->mount($asso, $ndf);
    $formComponent->abandonCreanceProposed = true;
    $formComponent->submit();

    // Assertions post-submit
    $ndf->refresh();
    expect($ndf->statut)->toBe(StatutNoteDeFrais::Soumise);
    expect($ndf->abandon_creance_propose)->toBeTrue();
    expect($ndf->submitted_at)->not->toBeNull();

    // ── Étape 2 : Comptable constate l'abandon en back-office ──────────────

    // Déconnecter Jean du portail et passer au guard web
    Auth::guard('tiers-portail')->logout();

    TenantContext::clear();
    TenantContext::boot($asso);
    session(['current_association_id' => $asso->id]);

    $countBefore = Transaction::count();

    $this->actingAs($comptable);

    Livewire::test(BackOfficeShow::class, ['noteDeFrais' => $ndf])
        ->assertSet('dateDon', $dateNdf)
        ->set('choixValidation', 'abandon')
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', $dateNdf)
        ->set('dateDon', $dateNdf)
        ->call('confirmValidation')
        ->assertSet('showMiniForm', false)
        ->assertHasNoErrors();

    // ── Assertions DB post-abandon ──────────────────────────────────────────

    $ndf->refresh();
    expect($ndf->statut)->toBe(StatutNoteDeFrais::DonParAbandonCreances);
    expect($ndf->transaction_id)->not->toBeNull();
    expect($ndf->don_transaction_id)->not->toBeNull();

    // 2 nouvelles transactions
    expect(Transaction::count())->toBe($countBefore + 2);

    // Transaction Dépense réglée
    $txDepense = Transaction::find($ndf->transaction_id);
    expect($txDepense)->not->toBeNull();
    expect($txDepense->statut_reglement)->toBe(StatutReglement::Recu);

    // Transaction Don réglée
    $txDon = Transaction::find($ndf->don_transaction_id);
    expect($txDon)->not->toBeNull();
    expect($txDon->statut_reglement)->toBe(StatutReglement::Recu);

    // Montant = 120 €
    expect((float) $txDepense->montant_total)->toBe(120.0);
    expect((float) $txDon->montant_total)->toBe(120.0);

    // ── Étape 3 : Jean revoit sa NDF sur le portail ─────────────────────────

    Auth::guard()->logout();
    Auth::guard('tiers-portail')->login($jean);
    TenantContext::clear();
    TenantContext::boot($asso);

    $this->get("/portail/{$asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSee('Don par abandon de créance — acté le')
        ->assertSee('120');
});
