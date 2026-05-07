<?php

declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Enums\Newsletter\DesinscriptionAction;
use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Services\Newsletter\Exceptions\TiersHasDependenciesException;
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
     * Renvoie null si aucun, sinon ['tiers' => Tiers, 'kind' => 'email'|'fuzzy'].
     *
     * @return array{tiers: Tiers, kind: string}|null
     */
    public function suggestMatch(SubscriptionRequest $req): ?array
    {
        if ($req->email !== null && $req->email !== '') {
            $byEmail = Tiers::where('email', $req->email)->first();
            if ($byEmail !== null) {
                return ['tiers' => $byEmail, 'kind' => 'email'];
            }
        }

        $prenom = (string) ($req->prenom ?? '');
        $nom = (string) ($req->nom ?? '');
        if ($prenom !== '' && $nom !== '') {
            $byName = Tiers::whereRaw('LOWER(prenom) = ?', [mb_strtolower($prenom)])
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($nom)])
                ->first();
            if ($byName !== null) {
                return ['tiers' => $byName, 'kind' => 'fuzzy'];
            }
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

    public function applyUnsubscribeOptout(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $tiers = $req->tiers;
            if ($tiers !== null) {
                $tiers->email_optout = true;
                $tiers->save();
            }
            $req->desinscription_traitee_at = now();
            $req->desinscription_action = DesinscriptionAction::Optout;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }

    /** @throws TiersHasDependenciesException */
    public function applyUnsubscribeDelete(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $tiers = $req->tiers;
            if ($tiers === null) {
                // Tiers déjà supprimé (concurrence) — marquer simplement la ligne comme traitée
                $req->desinscription_traitee_at = now();
                $req->desinscription_action = DesinscriptionAction::Deleted;
                $req->processed_by_user_id = (int) Auth::id();
                $req->save();

                return;
            }
            $deps = $this->tiersService->countDependentRecords($tiers);
            if (array_sum($deps) > 0) {
                throw new TiersHasDependenciesException($deps);
            }
            $tiers->delete();
            $req->refresh(); // tiers_id devient null par cascade
            $req->desinscription_traitee_at = now();
            $req->desinscription_action = DesinscriptionAction::Deleted;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }

    public function applyUnsubscribeNoop(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $req->desinscription_traitee_at = now();
            $req->desinscription_action = DesinscriptionAction::Noop;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }
}
