<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\RecusFiscaux;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

it('affiche les valeurs actuelles de l\'asso', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'regime_fiscal_don' => 'RUP',
        'signataire_nom' => 'Jean',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->assertSet('eligibleRecuFiscal', true)
        ->assertSet('regimeFiscalDon', 'RUP')
        ->assertSet('signataireNom', 'Jean');
});

it('persiste les modifications', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->set('eligibleRecuFiscal', true)
        ->set('regimeFiscalDon', 'Intérêt général')
        ->set('signataireNom', 'Marie Curie')
        ->set('signataireQualite', 'Présidente')
        ->call('enregistrer')
        ->assertHasNoErrors();

    $asso->refresh();
    expect($asso->eligible_recu_fiscal)->toBeTrue();
    expect($asso->regime_fiscal_don)->toBe('Intérêt général');
    expect($asso->signataire_nom)->toBe('Marie Curie');
});
