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
        $cotisation->delete();
    }
}
