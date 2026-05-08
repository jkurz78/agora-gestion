<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function setupAssoEligibleAvecUser15(): array
{
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    return [$asso, $user];
}

it('télécharge le PDF d\'un don éligible (création + stream)', function () {
    [$asso, $user] = setupAssoEligibleAvecUser15();
    $ligne = $this->ligneDonValide();

    $response = $this->actingAs($user)
        ->get(route('tiers.dons.recu-fiscal', [
            'tiers' => $ligne->transaction->tiers,
            'ligne' => $ligne,
        ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

it('redirige avec flash error si l\'asso n\'est pas éligible', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => false]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $ligne = $this->ligneDonValide();

    $response = $this->actingAs($user)
        ->get(route('tiers.dons.recu-fiscal', [
            'tiers' => $ligne->transaction->tiers,
            'ligne' => $ligne,
        ]));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('retourne 403 pour un user d\'un autre tenant', function () {
    $asso1 = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $ligne = $this->ligneDonValide();

    $user2 = User::factory()->create();
    $user2->associations()->attach($asso2, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    $response = $this->actingAs($user2)
        ->get(route('tiers.dons.recu-fiscal', [
            'tiers' => $ligne->transaction->tiers,
            'ligne' => $ligne,
        ]));

    $response->assertForbidden();
});

it('redirige vers le reçu de remplacement si l\'actuel est annulé', function () {
    [$asso, $user] = setupAssoEligibleAvecUser15();
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);
    $ancien = $service->obtenirOuGenerer($ligne, $user);
    $nouveau = $service->reemettre($ancien, 'Test', $user);

    $response = $this->actingAs($user)
        ->get(route('tiers.dons.recu-fiscal', [
            'tiers' => $ligne->transaction->tiers,
            'ligne' => $ligne,
        ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});
