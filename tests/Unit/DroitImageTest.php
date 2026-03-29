<?php

declare(strict_types=1);

use App\Enums\DroitImage;

test('enum has expected cases', function () {
    expect(DroitImage::cases())->toHaveCount(4);
    expect(DroitImage::UsagePropre->value)->toBe('usage_propre');
    expect(DroitImage::UsageConfidentiel->value)->toBe('usage_confidentiel');
    expect(DroitImage::Diffusion->value)->toBe('diffusion');
    expect(DroitImage::Refus->value)->toBe('refus');
});

test('label returns french labels', function () {
    expect(DroitImage::UsagePropre->label())->toBe('Usage propre');
    expect(DroitImage::UsageConfidentiel->label())->toBe('Usage confidentiel');
    expect(DroitImage::Diffusion->label())->toBe('Diffusion');
    expect(DroitImage::Refus->label())->toBe('Refus');
});
