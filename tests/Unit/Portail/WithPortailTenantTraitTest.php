<?php

declare(strict_types=1);

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Tenant\TenantContext;

beforeEach(function () {
    TenantContext::clear();
});

it('boots the TenantContext from the component association', function () {
    $asso = Association::factory()->create();

    $component = new class
    {
        use WithPortailTenant;

        public ?Association $association = null;
    };
    $component->association = $asso;

    $component->bootedWithPortailTenant();

    expect(TenantContext::currentId())->toBe($asso->id);
});

it('does not boot if association is not set', function () {
    $component = new class
    {
        use WithPortailTenant;

        public ?Association $association = null;
    };

    $component->bootedWithPortailTenant();

    expect(TenantContext::hasBooted())->toBeFalse();
});
