<?php

declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    /** @param array<string, mixed> $tiersAttributes */
    public function createTiersFromBuffer(SubscriptionRequest $req, array $tiersAttributes): Tiers
    {
        return DB::transaction(function () use ($req, $tiersAttributes) {
            $tiers = Tiers::create($tiersAttributes);
            $req->tiers_id = $tiers->id;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();

            return $tiers;
        });
    }

    public function linkBufferToExistingTiers(SubscriptionRequest $req, Tiers $tiers): void
    {
        DB::transaction(function () use ($req, $tiers) {
            $req->tiers_id = $tiers->id;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }

    public function ignore(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $req->ignored_at = now();
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }
}
