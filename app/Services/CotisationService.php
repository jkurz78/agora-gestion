<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cotisation;
use App\Models\Tiers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class CotisationService
{
    public function create(Tiers $tiers, array $data): Cotisation
    {
        return DB::transaction(function () use ($tiers, $data) {
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(
                Carbon::parse($data['date_paiement'])
            );

            $data['tiers_id'] = $tiers->id;

            return Cotisation::create($data);
        });
    }

    public function delete(Cotisation $cotisation): void
    {
        if ($cotisation->rapprochement_id !== null) {
            throw new \RuntimeException('Cette cotisation est pointée dans un rapprochement et ne peut pas être supprimée.');
        }

        $cotisation->delete();
    }
}
