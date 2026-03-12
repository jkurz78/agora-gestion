<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Don;
use App\Models\Donateur;
use Illuminate\Support\Facades\DB;

final class DonService
{
    public function create(array $data, ?array $newDonateur = null): Don
    {
        return DB::transaction(function () use ($data, $newDonateur) {
            if ($newDonateur) {
                $donateur = Donateur::create($newDonateur);
                $data['donateur_id'] = $donateur->id;
            }

            $data['saisi_par'] = auth()->id();

            return Don::create($data);
        });
    }

    public function update(Don $don, array $data): Don
    {
        $don->update($data);

        return $don->fresh();
    }

    public function delete(Don $don): void
    {
        $don->delete();
    }
}
