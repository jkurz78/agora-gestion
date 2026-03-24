<?php

declare(strict_types=1);

namespace App\Enums;

enum HelloAssoEnvironnement: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Production => 'https://api.helloasso.com',
            self::Sandbox => 'https://api.helloasso-sandbox.com',
        };
    }

    public function adminUrl(): string
    {
        return match ($this) {
            self::Production => 'https://admin.helloasso.com',
            self::Sandbox => 'https://admin.helloasso-sandbox.com',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production',
            self::Sandbox => 'Sandbox',
        };
    }
}
