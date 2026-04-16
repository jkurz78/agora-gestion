<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\TenantModel;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('TenantModel fills association_id on create from TenantContext', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $fake = new class extends TenantModel
    {
        protected $table = 'tiers';

        protected $guarded = [];

        public $timestamps = true;
    };

    $row = $fake::create(['nom' => 'Dupont', 'type' => 'physique']);
    expect((int) $row->association_id)->toBe($asso->id);
});

it('TenantModel scopes queries to current tenant', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    DB::table('tiers')->insert([
        ['association_id' => $assoA->id, 'nom' => 'A1', 'type' => 'physique', 'created_at' => now(), 'updated_at' => now()],
        ['association_id' => $assoB->id, 'nom' => 'B1', 'type' => 'physique', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $fake = new class extends TenantModel
    {
        protected $table = 'tiers';

        protected $guarded = [];
    };

    TenantContext::boot($assoA);
    expect($fake::count())->toBe(1)
        ->and($fake::first()->nom)->toBe('A1');

    TenantContext::boot($assoB);
    expect($fake::count())->toBe(1)
        ->and($fake::first()->nom)->toBe('B1');
});

it('TenantModel does not overwrite association_id if already set', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();
    TenantContext::boot($assoA);

    $fake = new class extends TenantModel
    {
        protected $table = 'tiers';

        protected $guarded = [];

        public $timestamps = true;
    };

    $row = $fake::create(['nom' => 'Explicit', 'type' => 'physique', 'association_id' => $assoB->id]);
    expect((int) $row->association_id)->toBe($assoB->id);
});

it('TenantModel has association() belongsTo relation', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $fake = new class extends TenantModel
    {
        protected $table = 'tiers';

        protected $guarded = [];

        public $timestamps = true;
    };

    $row = $fake::create(['nom' => 'Test', 'type' => 'physique']);
    expect($row->association)->not->toBeNull()
        ->and($row->association->id)->toBe($asso->id);
});
