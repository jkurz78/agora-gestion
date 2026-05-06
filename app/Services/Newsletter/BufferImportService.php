<?php

declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Services\TiersService;

final class BufferImportService
{
    public function __construct(
        private readonly TiersService $tiersService,
    ) {}

    /**
     * Match email exact d'abord, puis (prenom, nom) en case-insensitive.
     * Renvoie null si aucun.
     */
    public function suggestMatch(SubscriptionRequest $req): ?Tiers
    {
        if ($req->email !== null && $req->email !== '') {
            $byEmail = Tiers::where('email', $req->email)->first();
            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        $prenom = (string) ($req->prenom ?? '');
        $nom = (string) ($req->nom ?? '');
        if ($prenom !== '' && $nom !== '') {
            return Tiers::whereRaw('LOWER(prenom) = ?', [mb_strtolower($prenom)])
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($nom)])
                ->first();
        }

        return null;
    }
}
