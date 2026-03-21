<?php

declare(strict_types=1);

namespace App\Services;

final class HelloAssoTestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $organisationNom = null,
        public readonly ?string $erreur = null,
    ) {}
}
