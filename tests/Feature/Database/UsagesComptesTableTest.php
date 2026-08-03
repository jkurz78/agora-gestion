<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the usages_comptes table with its final columns', function (): void {
    expect(Schema::hasTable('usages_comptes'))->toBeTrue()
        ->and(Schema::hasColumns('usages_comptes', [
            'id', 'association_id', 'compte_id', 'usage', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('enforces unique (association_id, compte_id, usage)', function (): void {
    $compte = Compte::factory()->create(['classe' => 7]);
    TenantContext::boot($compte->association);

    $attributes = [
        'association_id' => $compte->association_id,
        'compte_id' => $compte->id,
        'usage' => 'don',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::table('usages_comptes')->insert($attributes);

    expect(fn () => DB::table('usages_comptes')->insert($attributes))
        ->toThrow(QueryException::class);
});
