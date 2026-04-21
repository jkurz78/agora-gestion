<?php

declare(strict_types=1);

use App\Models\TenantModel;
use App\Models\TiersPortailOtp;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;

// Test 5 needs TenantContext cleared — override the global bootstrap
beforeEach(fn () => TenantContext::clear());

it('migration: table tiers_portail_otps exists with expected columns', function () {
    expect(Schema::hasTable('tiers_portail_otps'))->toBeTrue();

    $columns = [
        'id',
        'association_id',
        'email',
        'code_hash',
        'expires_at',
        'consumed_at',
        'attempts',
        'last_sent_at',
        'created_at',
        'updated_at',
    ];

    expect(Schema::hasColumns('tiers_portail_otps', $columns))->toBeTrue();
});

it('migration: tiers_portail_otps has composite index on (association_id, email)', function () {
    $indexes = Schema::getIndexes('tiers_portail_otps');

    $composite = collect($indexes)->first(function ($index) {
        $cols = $index['columns'];

        return in_array('association_id', $cols, true) && in_array('email', $cols, true);
    });

    expect($composite)->not->toBeNull(
        'Expected a composite index on (association_id, email) in tiers_portail_otps but none found.'
    );
});

it('migration: tiers has composite index on (association_id, email)', function () {
    $indexes = Schema::getIndexes('tiers');

    // Look for an index covering both association_id and email (may be 2-col or wider)
    $composite = collect($indexes)->first(function ($index) {
        $cols = $index['columns'];

        return in_array('association_id', $cols, true) && in_array('email', $cols, true);
    });

    expect($composite)->not->toBeNull(
        'Expected a composite index (association_id, email) on tiers — none found.'
    );
});

it('model: TiersPortailOtp extends TenantModel', function () {
    expect(TiersPortailOtp::class)->toExtend(TenantModel::class);
});

it('model: TiersPortailOtp has correct casts', function () {
    $otp = new TiersPortailOtp;
    $casts = $otp->getCasts();

    expect($casts)->toHaveKey('expires_at')
        ->and($casts['expires_at'])->toBe('datetime')
        ->and($casts)->toHaveKey('last_sent_at')
        ->and($casts['last_sent_at'])->toBe('datetime')
        ->and($casts)->toHaveKey('consumed_at')
        ->and($casts['consumed_at'])->toBe('datetime')
        ->and($casts)->toHaveKey('attempts')
        ->and($casts['attempts'])->toBe('integer');
});

it('model: TiersPortailOtp fillable contains required fields', function () {
    $otp = new TiersPortailOtp;
    $fillable = $otp->getFillable();

    foreach (['association_id', 'email', 'code_hash', 'expires_at', 'last_sent_at'] as $field) {
        expect(in_array($field, $fillable, true))->toBeTrue("Field '{$field}' missing from fillable");
    }
});

it('model: TenantScope is active — all() returns empty without TenantContext booted', function () {
    // TenantContext was cleared in beforeEach above
    expect(TenantContext::hasBooted())->toBeFalse();

    $result = TiersPortailOtp::all();

    expect($result)->toHaveCount(0);
});

it('config: portail.otp_length is 8', function () {
    expect(config('portail.otp_length'))->toBe(8);
});

it('config: portail has all required keys with correct values', function () {
    expect(config('portail.otp_ttl_minutes'))->toBe(10)
        ->and(config('portail.otp_max_attempts'))->toBe(3)
        ->and(config('portail.otp_cooldown_minutes'))->toBe(15)
        ->and(config('portail.otp_resend_seconds'))->toBe(60)
        ->and(config('portail.session_lifetime_minutes'))->toBe(60);
});
