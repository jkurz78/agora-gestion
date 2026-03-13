<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Depense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class DepenseService
{
    public function create(array $data, array $lignes): Depense
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
            $depense = Depense::create($data);
            foreach ($lignes as $ligne) {
                $depense->lignes()->create($ligne);
            }

            return $depense;
        });
    }

    public function update(Depense $depense, array $data, array $lignes): Depense
    {
        return DB::transaction(function () use ($depense, $data, $lignes) {
            $depense->update($data);
            $depense->lignes()->forceDelete();
            foreach ($lignes as $ligne) {
                $depense->lignes()->create($ligne);
            }

            return $depense->fresh();
        });
    }

    public function delete(Depense $depense): void
    {
        if ($depense->rapprochement_id !== null) {
            throw new \RuntimeException('Cette dépense est pointée dans un rapprochement et ne peut pas être supprimée.');
        }

        DB::transaction(function () use ($depense) {
            $depense->lignes()->delete();
            $depense->delete();
        });
    }
}
