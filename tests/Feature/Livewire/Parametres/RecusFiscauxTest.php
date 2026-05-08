<?php

declare(strict_types=1);

use App\Enums\RegimeFiscalDon;
use App\Enums\RoleAssociation;
use App\Livewire\Parametres\RecusFiscaux;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

it('affiche les valeurs actuelles de l\'asso', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'regime_fiscal_don' => RegimeFiscalDon::ReconnueUtilitePublique,
        'signataire_nom' => 'Jean',
        'loi_coluche_eligible' => true,
        'ifi_eligible' => false,
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->assertSet('eligibleRecuFiscal', true)
        ->assertSet('regimeFiscalDon', RegimeFiscalDon::ReconnueUtilitePublique->value)
        ->assertSet('signataireNom', 'Jean')
        ->assertSet('loiColucheEligible', true)
        ->assertSet('ifiEligible', false);
});

it('persiste les modifications avec enum + booléens', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->set('eligibleRecuFiscal', true)
        ->set('regimeFiscalDon', RegimeFiscalDon::InteretGeneral->value)
        ->set('signataireNom', 'Marie Curie')
        ->set('signataireQualite', 'Présidente')
        ->set('loiColucheEligible', true)
        ->set('ifiEligible', false)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $asso->refresh();
    expect($asso->eligible_recu_fiscal)->toBeTrue();
    expect($asso->regime_fiscal_don)->toBe(RegimeFiscalDon::InteretGeneral);
    expect($asso->signataire_nom)->toBe('Marie Curie');
    expect($asso->loi_coluche_eligible)->toBeTrue();
    expect($asso->ifi_eligible)->toBeFalse();
});

it('rejette une valeur de régime fiscal non valide', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->set('eligibleRecuFiscal', true)
        ->set('regimeFiscalDon', 'inexistant')
        ->call('enregistrer')
        ->assertHasErrors(['regimeFiscalDon']);
});

it('accepte regime_fiscal_don vide (null)', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->set('eligibleRecuFiscal', true)
        ->set('regimeFiscalDon', '')
        ->call('enregistrer')
        ->assertHasNoErrors();

    $asso->refresh();
    expect($asso->regime_fiscal_don)->toBeNull();
});

it('persiste loi_coluche et ifi independamment', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->set('eligibleRecuFiscal', true)
        ->set('loiColucheEligible', false)
        ->set('ifiEligible', true)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $asso->refresh();
    expect($asso->loi_coluche_eligible)->toBeFalse();
    expect($asso->ifi_eligible)->toBeTrue();
});
