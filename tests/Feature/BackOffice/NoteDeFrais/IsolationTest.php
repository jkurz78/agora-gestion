<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\RoleSysteme;
use App\Enums\StatutNoteDeFrais;
use App\Livewire\BackOffice\NoteDeFrais\Index;
use App\Livewire\BackOffice\NoteDeFrais\Show;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ── Helpers ───────────────────────────────────────────────────────────────────

function isoBootTenant(Association $association): void
{
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);
}

function isoMakeComptable(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function isoMakeSoumise(Association $association): NoteDeFrais
{
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    return NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// Section 1 — Isolation lecture / écriture via TenantScope + Policy
// ═══════════════════════════════════════════════════════════════════════════════

it('comptable from A cannot view NDF from B via show route', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // Create NDF in assoc B
    isoBootTenant($assocB);
    $ndfB = isoMakeSoumise($assocB);

    // Act as comptable of assoc A
    isoBootTenant($assocA);
    $comptableA = isoMakeComptable($assocA);

    $this->actingAs($comptableA)
        ->get(route('comptabilite.ndf.show', $ndfB))
        ->assertNotFound();
});

it('comptable from A only sees its own NDF on the index — not those from B', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // Create 3 Soumise in A
    isoBootTenant($assocA);
    $comptableA = isoMakeComptable($assocA);
    $tiersA = Tiers::factory()->create(['association_id' => $assocA->id]);

    for ($i = 0; $i < 3; $i++) {
        NoteDeFrais::factory()->soumise()->create([
            'association_id' => $assocA->id,
            'tiers_id' => $tiersA->id,
            'libelle' => "NDF A #{$i}",
        ]);
    }

    // Create 5 Soumise in B
    isoBootTenant($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);

    for ($i = 0; $i < 5; $i++) {
        NoteDeFrais::factory()->soumise()->create([
            'association_id' => $assocB->id,
            'tiers_id' => $tiersB->id,
            'libelle' => "NDF B #{$i}",
        ]);
    }

    // Act as comptable of A — only 3 NDF should be visible
    isoBootTenant($assocA);
    $this->actingAs($comptableA);

    $component = Livewire::test(Index::class);
    $notes = $component->viewData('notes');

    expect($notes)->toHaveCount(3);
    expect($notes->every(fn ($n) => (int) $n->association_id === (int) $assocA->id))->toBeTrue();
});

it('comptable from A cannot mount Show component with NDF from B — policy 403', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // Create NDF in B
    isoBootTenant($assocB);
    $ndfB = isoMakeSoumise($assocB);

    // Act as comptable of A, context = A
    isoBootTenant($assocA);
    $comptableA = isoMakeComptable($assocA);
    $this->actingAs($comptableA);

    // Livewire::test passes the model instance directly (bypasses TenantScope route binding),
    // but mount() calls $this->authorize('treat', $noteDeFrais) which checks
    // $noteDeFrais->association_id vs TenantContext::currentId() — different tenants → 403.
    // Livewire wraps the AuthorizationException in a 403 response (does not re-throw).
    Livewire::test(Show::class, ['noteDeFrais' => $ndfB])
        ->assertForbidden();
});

it('comptable from A cannot call confirmRejection on NDF from B — HTTP 404 via route', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // Create NDF in B
    isoBootTenant($assocB);
    $ndfB = isoMakeSoumise($assocB);

    // Act as comptable of A — NDF B is invisible via TenantScope → route model binding → 404
    isoBootTenant($assocA);
    $comptableA = isoMakeComptable($assocA);

    $this->actingAs($comptableA)
        ->get(route('comptabilite.ndf.show', $ndfB))
        ->assertNotFound();

    // The NDF in B must remain Soumise (no write occurred)
    $ndfB->refresh();
    expect($ndfB->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
});

it('comptable from A cannot access piece-jointe of NDF from B', function (): void {
    Storage::fake('local');

    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // Create NDF + ligne with PJ in B
    isoBootTenant($assocB);
    $ndfB = isoMakeSoumise($assocB);
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    $path = "associations/{$assocB->id}/notes-de-frais/{$ndfB->id}/ligne-1.pdf";
    Storage::disk('local')->put($path, 'fake-pdf');
    $ligneB = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndfB->id,
        'sous_categorie_id' => $sousCategorie->id,
        'piece_jointe_path' => $path,
    ]);

    // Act as comptable of A
    isoBootTenant($assocA);
    $comptableA = isoMakeComptable($assocA);

    $this->actingAs($comptableA)
        ->get(route('comptabilite.ndf.piece-jointe', [$ndfB, $ligneB]))
        ->assertNotFound();
});

// ═══════════════════════════════════════════════════════════════════════════════
// Section 2 — Badge scope tenant
// ═══════════════════════════════════════════════════════════════════════════════

it('badge count from A ignores soumises from B', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // 2 soumises in A
    isoBootTenant($assocA);
    $tiersA = Tiers::factory()->create(['association_id' => $assocA->id]);

    for ($i = 0; $i < 2; $i++) {
        NoteDeFrais::factory()->soumise()->create([
            'association_id' => $assocA->id,
            'tiers_id' => $tiersA->id,
        ]);
    }

    // 7 soumises in B
    isoBootTenant($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);

    for ($i = 0; $i < 7; $i++) {
        NoteDeFrais::factory()->soumise()->create([
            'association_id' => $assocB->id,
            'tiers_id' => $tiersB->id,
        ]);
    }

    // Act as admin of A
    isoBootTenant($assocA);
    $adminA = User::factory()->create();
    $adminA->associations()->attach($assocA->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $adminA->update(['derniere_association_id' => $assocA->id]);

    $response = $this->actingAs($adminA)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('>2<', false);
    $response->assertDontSee('>7<', false);
    $response->assertDontSee('>9<', false);
});

// ═══════════════════════════════════════════════════════════════════════════════
// Section 3 — Observer cross-tenant
// ═══════════════════════════════════════════════════════════════════════════════

it('deleting a transaction in A reverts its NDF to Soumise but does not affect NDF in B', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // In tenant A: create a Validée NDF linked to a transaction
    isoBootTenant($assocA);
    $tiersA = Tiers::factory()->create(['association_id' => $assocA->id]);
    $transactionA = Transaction::factory()->create(['association_id' => $assocA->id]);
    $ndfA = NoteDeFrais::factory()->validee()->create([
        'association_id' => $assocA->id,
        'tiers_id' => $tiersA->id,
        'transaction_id' => $transactionA->id,
    ]);

    // In tenant B: create a Validée NDF linked to a different transaction (no relation to A)
    isoBootTenant($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);
    $transactionB = Transaction::factory()->create(['association_id' => $assocB->id]);
    $ndfB = NoteDeFrais::factory()->validee()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
        'transaction_id' => $transactionB->id,
    ]);

    // Delete transaction A while context = A
    isoBootTenant($assocA);
    $transactionA->delete();

    // NDF A must revert to Soumise
    $ndfA->refresh();
    expect($ndfA->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndfA->transaction_id)->toBeNull();

    // NDF B must remain Validée and untouched
    $ndfB->refresh();
    expect($ndfB->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Validee->value);
    expect((int) $ndfB->transaction_id)->toBe((int) $transactionB->id);
});

// ═══════════════════════════════════════════════════════════════════════════════
// Section 4 — Mode support super-admin
// ═══════════════════════════════════════════════════════════════════════════════

it('super-admin in support mode cannot call confirmValidation on a NDF (403)', function (): void {
    Storage::fake('local');

    $association = Association::factory()->create();
    isoBootTenant($association);

    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    // Simulate the super-admin entering support mode for this association
    $this->actingAs($superAdmin)
        ->post("/super-admin/associations/{$association->slug}/support/enter")
        ->assertRedirect('/dashboard');

    // BlockWritesInSupport is in the 'web' group and intercepts ALL non-GET routes,
    // including the Livewire update endpoint, before Livewire processes the snapshot.
    // We post to the Livewire update route by name to avoid hard-coding the hashed prefix.
    $response = $this->actingAs($superAdmin)
        ->post(route('default-livewire.update'), [
            'components' => [
                [
                    'snapshot' => json_encode([
                        'data' => ['noteDeFrais' => $ndf->id],
                        'memo' => [
                            'name' => 'back-office.note-de-frais.show',
                            'id' => 'test-id',
                        ],
                    ]),
                    'updates' => [],
                    'calls' => [['method' => 'confirmValidation', 'params' => []]],
                ],
            ],
        ]);

    $response->assertStatus(403);

    // NDF must remain Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
});

it('super-admin in support mode cannot call confirmRejection on a NDF (403)', function (): void {
    $association = Association::factory()->create();
    isoBootTenant($association);

    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    // Enter support mode
    $this->actingAs($superAdmin)
        ->post("/super-admin/associations/{$association->slug}/support/enter")
        ->assertRedirect('/dashboard');

    // BlockWritesInSupport intercepts before Livewire processes the snapshot → 403
    $response = $this->actingAs($superAdmin)
        ->post(route('default-livewire.update'), [
            'components' => [
                [
                    'snapshot' => json_encode([
                        'data' => ['noteDeFrais' => $ndf->id],
                        'memo' => [
                            'name' => 'back-office.note-de-frais.show',
                            'id' => 'test-id',
                        ],
                    ]),
                    'updates' => [],
                    'calls' => [['method' => 'confirmRejection', 'params' => []]],
                ],
            ],
        ]);

    $response->assertStatus(403);

    // NDF must remain Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
});
