<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;

final class StandardLigneType implements LigneTypeInterface
{
    public function key(): NoteDeFraisLigneType
    {
        return NoteDeFraisLigneType::Standard;
    }

    public function validate(array $draft): void
    {
        // Validation déléguée au service (submit + saveDraft).
    }

    public function computeMontant(array $draft): float
    {
        $raw = $draft['montant'] ?? 0;
        if (is_string($raw)) {
            $raw = str_replace(',', '.', $raw);
        }

        return (float) $raw;
    }

    public function metadata(array $draft): array
    {
        return [];
    }

    public function renderDescription(array $metadata): string
    {
        return '';
    }

    public function resolveSousCategorieId(?int $requestedId): ?int
    {
        return $requestedId;
    }
}
