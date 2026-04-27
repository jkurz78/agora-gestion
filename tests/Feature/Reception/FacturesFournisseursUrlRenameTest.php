<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────────────────────

function fpRenameAdminUser(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    TenantContext::clear();
    Storage::fake('local');

    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    $this->admin = fpRenameAdminUser($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

// (a) Nouvelle URL retourne 200 pour un Admin
it('GET /comptabilite/factures-fournisseurs retourne 200 pour un Admin', function (): void {
    $response = $this->actingAs($this->admin)
        ->get('/comptabilite/factures-fournisseurs');

    $response->assertOk();
});

// (b) L'ancienne URL retourne une redirection 301 vers la nouvelle
it('GET /factures-partenaires/a-comptabiliser retourne 301 vers /comptabilite/factures-fournisseurs', function (): void {
    $response = $this->actingAs($this->admin)
        ->get('/factures-partenaires/a-comptabiliser');

    $response->assertRedirect('/comptabilite/factures-fournisseurs');
    $response->assertStatus(301);
});

// (c) Le nouveau nom de route résout le bon chemin
it('route(comptabilite.factures-fournisseurs.index) résout /comptabilite/factures-fournisseurs', function (): void {
    expect(route('comptabilite.factures-fournisseurs.index', [], false))
        ->toBe('/comptabilite/factures-fournisseurs');
});

// (d) L'ancien nom de route n'est plus enregistré
it('l\'ancien nom back-office.factures-partenaires.index n\'est plus enregistré', function (): void {
    expect(Route::has('back-office.factures-partenaires.index'))->toBeFalse();
});

// (e) La route PDF fonctionne avec le nouveau nom
it('route(comptabilite.factures-fournisseurs.depot-pdf) résout le bon chemin', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);

    $url = route('comptabilite.factures-fournisseurs.depot-pdf', ['depot' => $depot->id], false);

    expect($url)->toBe('/comptabilite/factures-fournisseurs/'.$depot->id.'/pdf');
});
