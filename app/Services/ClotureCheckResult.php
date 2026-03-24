<?php

declare(strict_types=1);

namespace App\Services;

final class ClotureCheckResult
{
    /**
     * @param  CheckItem[]  $bloquants
     * @param  CheckItem[]  $avertissements
     * @param  array<string, float>  $soldesComptes
     */
    public function __construct(
        public readonly array $bloquants,
        public readonly array $avertissements,
        public readonly array $soldesComptes,
    ) {}

    public function peutCloturer(): bool
    {
        return collect($this->bloquants)->every(fn (CheckItem $c): bool => $c->ok);
    }
}
