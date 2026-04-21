<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Livewire\BackOffice\NoteDeFrais\Show;
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
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ── Helpers ──────────────────────────────────────────────────────────────────

function constaterAbandonMakeAdmin(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function constaterAbandonBootTenant(Association $association): void
{
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);
}

/**
 * Creates an NDF with abandon_creance_propose + one ligne + one AbandonCreance sous-cat.
 *
 * @return array{ndf: NoteDeFrais, compte: CompteBancaire, scAbandon: SousCategorie}
 */
function constaterAbandonSetupHappyPath(Association $association, string $ndfDate = '2026-03-10'): array
{
    Storage::fake('local');

    $catRecette = Categorie::factory()->create([
        'association_id' => $association->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    $scAbandon = SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $association->id,
        'categorie_id' => $catRecette->id,
        'nom' => 'Don abandon test',
    ]);

    $compte = CompteBancaire::factory()->create([
        'association_id' => $association->id,
        'actif_recettes_depenses' => true,
        'est_systeme' => false,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'Frais abandon test',
        'date' => $ndfDate,
        'abandon_creance_propose' => true,
    ]);

    $sousCategorie = SousCategorie::factory()->create(['association_id' => $association->id]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'libelle' => 'Déplacement',
        'montant' => '75.00',
        'piece_jointe_path' => null,
    ]);

    return ['ndf' => $ndf, 'compte' => $compte, 'scAbandon' => $scAbandon];
}

// ── 1. Propriété dateDon initialisée à ndf->date au mount ────────────────────

it('initialise dateDon à la date de la NDF au mount', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date' => '2026-03-10',
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSet('dateDon', '2026-03-10');
});

// ── 2. openAbandonForm() → showAbandonForm = true ────────────────────────────

it('openAbandonForm passe showAbandonForm à true', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSet('showAbandonForm', false)
        ->call('openAbandonForm')
        ->assertSet('showAbandonForm', true);
});

// ── 3. setDateDonToday() → dateDon = today ───────────────────────────────────

it('setDateDonToday passe dateDon à aujourd\'hui', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date' => '2026-01-01',
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->call('setDateDonToday')
        ->assertSet('dateDon', today()->format('Y-m-d'));
});

// ── 4. constaterAbandon happy path — 2 Transactions créées + statut NDF mis à jour

it('constaterAbandon crée 2 Transactions réglées et passe la NDF en DonParAbandonCreances', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $setup = constaterAbandonSetupHappyPath($association, '2026-03-10');
    $ndf = $setup['ndf'];
    $compte = $setup['compte'];

    $this->actingAs($admin);

    $countBefore = Transaction::count();

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', '2026-03-10')
        ->set('dateDon', '2026-03-10')
        ->call('constaterAbandon');

    // 2 nouvelles Transactions
    expect(Transaction::count())->toBe($countBefore + 2);

    // Statut NDF mis à jour
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::DonParAbandonCreances->value);
    expect($ndf->transaction_id)->not->toBeNull();
    expect($ndf->don_transaction_id)->not->toBeNull();

    // Transaction Dépense réglée
    $txDepense = Transaction::find($ndf->transaction_id);
    expect($txDepense)->not->toBeNull();
    expect($txDepense->type)->toBe(TypeTransaction::Depense);
    expect($txDepense->statut_reglement)->toBe(StatutReglement::Recu);

    // Transaction Don réglée
    $txDon = Transaction::find($ndf->don_transaction_id);
    expect($txDon)->not->toBeNull();
    expect($txDon->type)->toBe(TypeTransaction::Recette);
    expect($txDon->statut_reglement)->toBe(StatutReglement::Recu);
});

// ── 5. dateDon modifiée → Transaction Don avec la bonne date ─────────────────

it('constaterAbandon utilise la dateDon modifiée pour la Transaction Don', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $setup = constaterAbandonSetupHappyPath($association, '2026-03-10');
    $ndf = $setup['ndf'];
    $compte = $setup['compte'];

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', '2026-03-10')
        ->set('dateDon', '2026-04-05')
        ->call('constaterAbandon');

    $ndf->refresh();
    $txDon = Transaction::find($ndf->don_transaction_id);
    expect($txDon)->not->toBeNull();
    expect($txDon->date->format('Y-m-d'))->toBe('2026-04-05');
});

// ── 6. Succès → flash success + redirect vers la page show ───────────────────

it('constaterAbandon flash success et redirige vers comptabilite.ndf.show', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $setup = constaterAbandonSetupHappyPath($association);
    $ndf = $setup['ndf'];
    $compte = $setup['compte'];

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', '2026-03-10')
        ->set('dateDon', '2026-03-10')
        ->call('constaterAbandon')
        ->assertRedirect(route('comptabilite.ndf.show', $ndf));
});

// ── 7. Échec service (pas de sous-cat AbandonCreance) → flash error, NDF Soumise

it('constaterAbandon flash error et NDF reste Soumise si aucune sous-cat AbandonCreance', function (): void {
    Storage::fake('local');

    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $compte = CompteBancaire::factory()->create([
        'association_id' => $association->id,
        'actif_recettes_depenses' => true,
        'est_systeme' => false,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'abandon_creance_propose' => true,
        'date' => '2026-03-10',
    ]);

    $sousCategorie = SousCategorie::factory()->create(['association_id' => $association->id]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '50.00',
        'piece_jointe_path' => null,
    ]);

    $countBefore = Transaction::count();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('compteId', $compte->id)
        ->set('modePaiement', 'virement')
        ->set('dateComptabilisation', '2026-03-10')
        ->set('dateDon', '2026-03-10')
        ->call('constaterAbandon');

    // NDF reste Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);

    // Aucune Transaction créée
    expect(Transaction::count())->toBe($countBefore);
});

// ── 8. Validation front — champs requis ──────────────────────────────────────

it('constaterAbandon retourne des erreurs de validation si champs requis manquants', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);
    $admin = constaterAbandonMakeAdmin($association);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->set('compteId', null)
        ->set('modePaiement', '')
        ->set('dateComptabilisation', '')
        ->set('dateDon', '')
        ->call('constaterAbandon')
        ->assertHasErrors(['compteId', 'modePaiement', 'dateComptabilisation', 'dateDon']);
});

// ── 9. Policy : Gestionnaire → 403 ───────────────────────────────────────────

it('retourne 403 pour un gestionnaire accédant à la page show NDF', function (): void {
    $association = Association::factory()->create();
    constaterAbandonBootTenant($association);

    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($association->id, [
        'role' => RoleAssociation::Gestionnaire->value,
        'joined_at' => now(),
    ]);
    $gestionnaire->update(['derniere_association_id' => $association->id]);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'abandon_creance_propose' => true,
    ]);

    $this->actingAs($gestionnaire)
        ->get(route('comptabilite.ndf.show', $ndf))
        ->assertForbidden();
});

// ── 10. Isolation tenant — NDF asso B → 404 ──────────────────────────────────

it('retourne 404 quand la NDF appartient à une autre association', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    constaterAbandonBootTenant($assocA);
    $adminA = constaterAbandonMakeAdmin($assocA);

    TenantContext::clear();
    TenantContext::boot($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);
    $ndfB = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
    ]);

    constaterAbandonBootTenant($assocA);

    $this->actingAs($adminA)
        ->get(route('comptabilite.ndf.show', $ndfB))
        ->assertNotFound();
});
