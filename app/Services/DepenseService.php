<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Depense;
use Illuminate\Support\Facades\DB;

final class DepenseService
{
    public function create(array $data, array $lignes): Depense
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
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
        DB::transaction(function () use ($depense) {
            $depense->lignes()->delete();
            $depense->delete();
        });
    }
}
