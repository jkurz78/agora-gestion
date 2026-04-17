<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('runs tenant:benchmark in smoke mode without fatal', function () {
    $code = Artisan::call('tenant:benchmark', ['--tenants' => 2, '--transactions' => 10]);
    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Dashboard');
});

it('tenant:benchmark outputs a result table with expected screens', function () {
    Artisan::call('tenant:benchmark', ['--tenants' => 1, '--transactions' => 5]);
    $output = Artisan::output();

    expect($output)->toContain('Operations')
        ->and($output)->toContain('Tiers')
        ->and($output)->toContain('Factures');
});
