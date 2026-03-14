<?php

// app/Services/TiersService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;
use Illuminate\Support\Facades\DB;

final class TiersService
{
    public function create(array $data): Tiers
    {
        return DB::transaction(fn (): Tiers => Tiers::create($data));
    }

    public function update(Tiers $tiers, array $data): Tiers
    {
        return DB::transaction(function () use ($tiers, $data): Tiers {
            $tiers->update($data);

            return $tiers->fresh();
        });
    }

    public function delete(Tiers $tiers): void
    {
        // Plan B ajoutera ici la vérification des FK (dons, cotisations, depenses, recettes)
        DB::transaction(function () use ($tiers): void {
            $tiers->delete();
        });
    }
}
