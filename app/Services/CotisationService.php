<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cotisation;
use App\Models\Membre;

final class CotisationService
{
    public function create(Membre $membre, array $data): Cotisation
    {
        return $membre->cotisations()->create($data);
    }

    public function delete(Cotisation $cotisation): void
    {
        if ($cotisation->rapprochement_id !== null) {
            throw new \RuntimeException("Cette cotisation est pointée dans un rapprochement et ne peut pas être supprimée.");
        }

        $cotisation->delete();
    }
}
