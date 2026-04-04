<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;

it('has two cases', function () {
    expect(TwoFactorMethod::cases())->toHaveCount(2);
});

it('provides French labels', function () {
    expect(TwoFactorMethod::Email->label())->toBe('OTP par email');
    expect(TwoFactorMethod::Totp->label())->toBe('Application (TOTP)');
});
