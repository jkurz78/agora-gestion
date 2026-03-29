<?php

declare(strict_types=1);

namespace App\Enums;

enum DroitImage: string
{
    case UsagePropre = 'usage_propre';
    case UsageConfidentiel = 'usage_confidentiel';
    case Diffusion = 'diffusion';
    case Refus = 'refus';

    public function label(): string
    {
        return match ($this) {
            self::UsagePropre => 'Usage propre',
            self::UsageConfidentiel => 'Usage confidentiel',
            self::Diffusion => 'Diffusion',
            self::Refus => 'Refus',
        };
    }
}
