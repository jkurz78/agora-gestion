<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\UsageComptable;
use App\Livewire\Parametres\Comptabilite\UsagesComptables;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\Compte;
use App\Models\User;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Tâche 3 — écran de configuration de l'usage Gratuité (709A).
 *
 * `tests/Pest.php` boote un tenant par défaut dans son beforeEach global mais
 * l'affecte à une variable locale : on relit l'association courante via
 * `TenantContext::currentId()`, comme dans CompteUsageComptableTest.
 */
beforeEach(function () {
    $this->admin = User::factory()->create();
    AssociationUser::create([
        'user_id' => $this->admin->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($this->admin);
});

it('un admin peut désigner le compte de gratuité', function () {
    $compte = Compte::factory()->numero('709A')->create();

    Livewire::test(UsagesComptables::class)
        ->set('gratuiteSelectedId', $compte->id)
        ->call('saveGratuite')
        ->assertHasNoErrors();

    expect(Compte::forUsage(UsageComptable::Gratuite)->pluck('id')->all())
        ->toBe([$compte->id]);
});

it('mount recharge le compte de gratuité déjà configuré', function () {
    $compte = Compte::factory()->numero('709A')->create();
    app(UsagesComptablesService::class)->setGratuite($compte->id);

    Livewire::test(UsagesComptables::class)
        ->assertSet('gratuiteSelectedId', $compte->id);
});

it('un non-admin est refusé', function () {
    $autre = User::factory()->create();
    AssociationUser::create([
        'user_id' => $autre->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Consultation->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($autre);

    Livewire::test(UsagesComptables::class)->assertForbidden();
});

it('la cardinalité mono est respectée : le second compte remplace le premier', function () {
    $premier = Compte::factory()->numero('709A')->create();
    $second = Compte::factory()->numero('709B')->create();

    Livewire::test(UsagesComptables::class)
        ->set('gratuiteSelectedId', $premier->id)
        ->call('saveGratuite');

    Livewire::test(UsagesComptables::class)
        ->set('gratuiteSelectedId', $second->id)
        ->call('saveGratuite');

    expect(Compte::forUsage(UsageComptable::Gratuite)->pluck('id')->all())
        ->toBe([$second->id]);
});
