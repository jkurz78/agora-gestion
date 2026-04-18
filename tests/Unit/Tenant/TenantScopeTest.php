<?php

declare(strict_types=1);

use App\Models\Association;
use App\Tenant\TenantContext;
use App\Tenant\TenantScope;
use Illuminate\Database\Eloquent\Model;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('applies where association_id = current tenant when booted', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $fake = new class extends Model
    {
        protected $table = 'tiers';
    };

    $builder = $fake->newQuery();
    (new TenantScope)->apply($builder, $fake);

    $wheres = $builder->getQuery()->wheres;
    expect($wheres)->toHaveCount(1)
        ->and($wheres[0]['column'])->toBe('tiers.association_id')
        ->and($wheres[0]['value'])->toBe($asso->id);
});

it('blocks all rows (fail-closed) when TenantContext not booted', function () {
    $fake = new class extends Model
    {
        protected $table = 'tiers';
    };

    $builder = $fake->newQuery();
    (new TenantScope)->apply($builder, $fake);

    // Fail-closed: a whereRaw('1 = 0') clause must be present so that
    // unbooted queries return no rows instead of leaking all tenant data.
    $wheres = $builder->getQuery()->wheres;
    expect($wheres)->toHaveCount(1)
        ->and($wheres[0]['type'])->toBe('raw')
        ->and((string) $wheres[0]['sql'])->toBe('1 = 0');
});
