<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('runs tenant:benchmark in smoke mode without fatal', function () {
    $code = Artisan::call('tenant:benchmark', ['--tenants' => 2, '--transactions' => 10]);
    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Dashboard');
});

it('runs tenant:benchmark and exercises all 6 screens', function () {
    $code = Artisan::call('tenant:benchmark', ['--tenants' => 1, '--transactions' => 5]);
    expect($code)->toBe(0);
    $output = Artisan::output();
    foreach (['Dashboard', 'Operations list', 'Tiers 360', 'Factures', 'Rapports CERFA', 'Analyse pivot'] as $screen) {
        expect($output)->toContain($screen);
    }
});

it('refuses to run in production-like environment without --force', function () {
    // Override environment and restore it after regardless of outcome.
    app()->detectEnvironment(fn () => 'production');

    try {
        $code = Artisan::call('tenant:benchmark', ['--tenants' => 1, '--transactions' => 1]);
        expect($code)->toBe(Symfony\Component\Console\Command\Command::FAILURE);
        expect(Artisan::output())->toContain('Refus de');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});
