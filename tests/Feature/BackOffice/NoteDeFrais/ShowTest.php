<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutExercice;
use App\Enums\StatutNoteDeFrais;
use App\Livewire\BackOffice\NoteDeFrais\Show;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ── Helpers ──────────────────────────────────────────────────────────────────

function ndfShowMakeUserWithRole(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function ndfShowBootTenant(Association $association): void
{
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);
}

// ── 1. Admin voit NDF Soumise → en-tête + lignes + boutons ──────────────────

it('shows header, lines and Valider/Rejeter buttons for a Soumise NDF', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'Frais déplacement test',
    ]);

    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'libelle' => 'Billet train',
        'montant' => '55.00',
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSee('Frais déplacement test')
        ->assertSee('Billet train')
        ->assertSee('55')
        ->assertSee('Valider')
        ->assertSee('Rejeter')
        ->assertOk();
});

// ── 2. Comptable voit NDF Validée → panneau Transaction ──────────────────────

it('shows transaction panel for a Validee NDF', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $comptable = ndfShowMakeUserWithRole($association, RoleAssociation::Comptable);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $transaction = Transaction::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF validée',
        'transaction_id' => $transaction->id,
    ]);

    $this->actingAs($comptable);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSee('Transaction #'.$transaction->id)
        ->assertSee('Ouvrir la transaction comptable')
        ->assertOk();
});

// ── 3. Comptable voit NDF Rejetée → motif affiché ────────────────────────────

it('shows rejection motif for a Rejetee NDF', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $comptable = ndfShowMakeUserWithRole($association, RoleAssociation::Comptable);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'statut' => StatutNoteDeFrais::Rejetee->value,
        'motif_rejet' => 'Justificatifs insuffisants',
    ]);

    $this->actingAs($comptable);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSee('Justificatifs insuffisants')
        ->assertOk();
});

// ── 4. Gestionnaire → 403 ────────────────────────────────────────────────────

it('returns 403 for a Gestionnaire trying to access show', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $gestionnaire = ndfShowMakeUserWithRole($association, RoleAssociation::Gestionnaire);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($gestionnaire)
        ->get(route('comptabilite.ndf.show', $ndf))
        ->assertForbidden();
});

// ── 5. NDF asso B → 404 (TenantScope) ────────────────────────────────────────

it('returns 404 when accessing NDF from another association', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    ndfShowBootTenant($assocA);
    $admin = ndfShowMakeUserWithRole($assocA, RoleAssociation::Admin);

    TenantContext::clear();
    TenantContext::boot($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);
    $ndfB = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
    ]);

    ndfShowBootTenant($assocA);

    $this->actingAs($admin)
        ->get(route('comptabilite.ndf.show', $ndfB))
        ->assertNotFound();
});

// ── 6. openMiniForm() → $showMiniForm = true + champs pré-remplis ────────────

it('openMiniForm sets showMiniForm to true and pre-fills fields', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-11-15',
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSet('showMiniForm', false)
        ->assertSet('dateComptabilisation', '2025-11-15')
        ->assertSet('modePaiement', 'virement')
        ->call('openMiniForm')
        ->assertSet('showMiniForm', true);
});

// ── 7. setDateToday() → dateComptabilisation = today ─────────────────────────

it('setDateToday sets dateComptabilisation to today', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-01-01',
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->call('setDateToday')
        ->assertSet('dateComptabilisation', today()->format('Y-m-d'));
});

// ── 8. confirmValidation happy path ──────────────────────────────────────────

it('confirmValidation happy path creates transaction and sets NDF to Validee', function (): void {
    Storage::fake('local');

    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $compte = CompteBancaire::factory()->create([
        'association_id' => $association->id,
        'actif_recettes_depenses' => true,
    ]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'Frais test',
        'date' => '2025-10-15',
    ]);

    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '42.00',
        'piece_jointe_path' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', '2025-10-15')
        ->call('confirmValidation');

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Validee->value);
    expect($ndf->transaction_id)->not->toBeNull();
});

// ── 9. confirmValidation avec compte_id invalide → erreur validation ──────────

it('confirmValidation with invalid compte_id returns a validation error', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('showMiniForm', true)
        ->set('compteId', 99999)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', '2025-10-15')
        ->call('confirmValidation')
        ->assertHasErrors(['compteId'])
        ->assertSet('showMiniForm', true);
});

// ── 10. confirmValidation avec mode_paiement invalide → erreur ───────────────

it('confirmValidation with invalid modePaiement returns a validation error', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $compte = CompteBancaire::factory()->create([
        'association_id' => $association->id,
        'actif_recettes_depenses' => true,
    ]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('showMiniForm', true)
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'invalid_mode')
        ->set('dateComptabilisation', '2025-10-15')
        ->call('confirmValidation')
        ->assertHasErrors(['modePaiement'])
        ->assertSet('showMiniForm', true);
});

// ── 11. confirmValidation avec exercice clôturé → flash error, NDF Soumise ───

it('confirmValidation with closed exercice flashes error and NDF stays Soumise', function (): void {
    Storage::fake('local');

    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $compte = CompteBancaire::factory()->create([
        'association_id' => $association->id,
        'actif_recettes_depenses' => true,
    ]);

    // Create a closed exercice directly (no factory exists for Exercice)
    Exercice::create([
        'association_id' => $association->id,
        'annee' => 2023,
        'statut' => StatutExercice::Cloture->value,
    ]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date' => '2024-01-15', // in exercice 2023-2024
    ]);

    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '10.00',
        'piece_jointe_path' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', '2024-01-15')
        ->call('confirmValidation');

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
});

// ── 12. openRejectModal() → $showRejectModal = true ──────────────────────────

it('openRejectModal sets showRejectModal to true', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSet('showRejectModal', false)
        ->call('openRejectModal')
        ->assertSet('showRejectModal', true);
});

// ── 13. confirmRejection avec motif valide → redirect index + NDF Rejetée ────

it('confirmRejection with valid motif redirects to index and sets NDF to Rejetee', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('motifRejet', 'Justificatifs manquants')
        ->call('confirmRejection')
        ->assertRedirect(route('comptabilite.ndf.index'));

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Rejetee->value);
    expect($ndf->motif_rejet)->toBe('Justificatifs manquants');
});

// ── 14. confirmRejection avec motif vide → erreur validation, modal reste ouverte

it('confirmRejection with empty motif returns validation error', function (): void {
    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('showRejectModal', true)
        ->set('motifRejet', '')
        ->call('confirmRejection')
        ->assertHasErrors(['motifRejet'])
        ->assertSet('showRejectModal', true);
});

// ── 15. Accès PJ d'une ligne → 200 + content-type ────────────────────────────

it('piece-jointe route returns 200 for an authorized admin with a valid PJ', function (): void {
    Storage::fake('local');

    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $path = "associations/{$association->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf";
    Storage::disk('local')->put($path, 'fake-pdf-content');

    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    $ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => $path,
    ]);

    $this->actingAs($admin)
        ->get(route('comptabilite.ndf.piece-jointe', [$ndf, $ligne]))
        ->assertOk();
});

// ── 16. PJ d'une ligne d'une autre NDF → 404 (defensive check) ───────────────

it('piece-jointe returns 404 if the ligne belongs to a different NDF', function (): void {
    Storage::fake('local');

    $association = Association::factory()->create();
    ndfShowBootTenant($association);

    $admin = ndfShowMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf1 = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);
    $ndf2 = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $path = "associations/{$association->id}/notes-de-frais/{$ndf2->id}/ligne-1.pdf";
    Storage::disk('local')->put($path, 'fake-pdf-content');

    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    $ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf2->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => $path,
    ]);

    // Pass ndf1 but ligne belongs to ndf2 → defensive 404
    $this->actingAs($admin)
        ->get(route('comptabilite.ndf.piece-jointe', [$ndf1, $ligne]))
        ->assertNotFound();
});

// ── 17. PJ d'une NDF asso B → 404 (tenant scope) ────────────────────────────

it('piece-jointe returns 404 when the NDF belongs to another association', function (): void {
    Storage::fake('local');

    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    ndfShowBootTenant($assocA);
    $adminA = ndfShowMakeUserWithRole($assocA, RoleAssociation::Admin);

    TenantContext::clear();
    TenantContext::boot($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);
    $ndfB = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
    ]);
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    $ligneB = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndfB->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => "associations/{$assocB->id}/ndf/{$ndfB->id}/f.pdf",
    ]);
    Storage::disk('local')->put($ligneB->piece_jointe_path, 'content');

    ndfShowBootTenant($assocA);

    $this->actingAs($adminA)
        ->get(route('comptabilite.ndf.piece-jointe', [$ndfB, $ligneB]))
        ->assertNotFound();
});
