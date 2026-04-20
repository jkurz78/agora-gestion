<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;

final class LigneTypeRegistry
{
    /** @var array<string, LigneTypeInterface> */
    private array $cache = [];

    public function for(NoteDeFraisLigneType $type): LigneTypeInterface
    {
        if (! isset($this->cache[$type->value])) {
            $this->cache[$type->value] = match ($type) {
                NoteDeFraisLigneType::Standard => new StandardLigneType(),
                NoteDeFraisLigneType::Kilometrique => new KilometriqueLigneType(),
            };
        }

        return $this->cache[$type->value];
    }
}
