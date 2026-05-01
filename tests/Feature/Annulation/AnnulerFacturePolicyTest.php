<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Models\Association;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

// ─── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->tiers = null; // lazy
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function policyCreateUserWithRole(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function policyCreateFacture(Association $association): Facture
{
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $user = User::factory()->create();
    $exercice = app(ExerciceService::class)->current();

    return Facture::create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'statut' => StatutFacture::Validee,
        'date' => now()->toDateString(),
        'montant_total' => 100,
        'saisi_par' => $user->id,
        'exercice' => $exercice,
        'numero' => sprintf('F-%d-0001', $exercice),
    ]);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('gestionnaire_refuse', function (): void {
    $user = policyCreateUserWithRole($this->association, RoleAssociation::Gestionnaire);
    $facture = policyCreateFacture($this->association);

    $this->actingAs($user);

    expect(Gate::denies('annuler', $facture))->toBeTrue();
});

test('comptable_accepte', function (): void {
    $user = policyCreateUserWithRole($this->association, RoleAssociation::Comptable);
    $facture = policyCreateFacture($this->association);

    $this->actingAs($user);

    expect(Gate::allows('annuler', $facture))->toBeTrue();
});

test('admin_accepte', function (): void {
    $user = policyCreateUserWithRole($this->association, RoleAssociation::Admin);
    $facture = policyCreateFacture($this->association);

    $this->actingAs($user);

    expect(Gate::allows('annuler', $facture))->toBeTrue();
});

test('consultation_refuse', function (): void {
    $user = policyCreateUserWithRole($this->association, RoleAssociation::Consultation);
    $facture = policyCreateFacture($this->association);

    $this->actingAs($user);

    expect(Gate::denies('annuler', $facture))->toBeTrue();
});
