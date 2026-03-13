<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VirementInterne;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class VirementInterneService
{
    public function create(array $data): VirementInterne
    {
        return DB::transaction(function () use ($data) {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));

            return VirementInterne::create($data);
        });
    }

    public function update(VirementInterne $virement, array $data): VirementInterne
    {
        return DB::transaction(function () use ($virement, $data) {
            $virement->update($data);

            return $virement->fresh();
        });
    }

    public function delete(VirementInterne $virement): void
    {
        if ($virement->rapprochement_source_id !== null || $virement->rapprochement_destination_id !== null) {
            throw new \RuntimeException('Ce virement est pointé dans un rapprochement et ne peut pas être supprimé.');
        }

        DB::transaction(function () use ($virement) {
            $virement->delete();
        });
    }
}
