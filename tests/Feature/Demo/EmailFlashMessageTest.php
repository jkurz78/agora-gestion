<?php

declare(strict_types=1);

use App\Support\FlashMessages;

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
});

it('FlashMessages::emailSent() contains "mode démo" when demo is active', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    expect(FlashMessages::emailSent())->toContain('mode démo');
});

it('FlashMessages::emailSent() does not contain "mode démo" when not in demo mode', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    expect(FlashMessages::emailSent())->not->toContain('mode démo');
});

it('FlashMessages::emailSent() returns a non-empty string in any environment', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(FlashMessages::emailSent())->toBeString()->not->toBeEmpty();
});
