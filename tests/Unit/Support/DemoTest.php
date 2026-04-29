<?php

declare(strict_types=1);

use App\Support\Demo;

test('Demo::isActive() returns false in local environment', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    expect(Demo::isActive())->toBeFalse();
});

test('Demo::isActive() returns false in testing environment', function (): void {
    app()->detectEnvironment(fn (): string => 'testing');

    expect(Demo::isActive())->toBeFalse();
});

test('Demo::isActive() returns false in production environment', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(Demo::isActive())->toBeFalse();
});

test('Demo::isActive() returns true in demo environment', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    expect(Demo::isActive())->toBeTrue();
});
