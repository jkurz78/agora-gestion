<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Models\Tiers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class AuthSessionService
{
    private const SESSION_KEY = 'portail.pending_tiers_ids';

    /**
     * Stocke les IDs de Tiers en attente de sélection dans la session.
     *
     * @param  list<int>  $tiersIds
     */
    public function markPendingTiers(array $tiersIds): void
    {
        session([self::SESSION_KEY => array_values(array_map('intval', $tiersIds))]);
    }

    /**
     * Retourne true si la session contient ≥ 2 Tiers en attente de sélection.
     */
    public function hasPendingChoice(): bool
    {
        return count((array) session(self::SESSION_KEY, [])) >= 2;
    }

    /**
     * Retourne les modèles Tiers correspondant aux IDs en session (scope tenant appliqué).
     *
     * @return Collection<int, Tiers>
     */
    public function pendingTiers(): Collection
    {
        /** @var list<int> $ids */
        $ids = (array) session(self::SESSION_KEY, []);

        if ($ids === []) {
            return Tiers::whereRaw('1 = 0')->get();
        }

        return Tiers::whereIn('id', $ids)->get();
    }

    /**
     * Connecte le Tiers identifié par $tiersId sur la garde tiers-portail,
     * à condition qu'il soit dans la liste pending.
     *
     * @throws AuthorizationException si $tiersId n'est pas dans la liste pending
     */
    public function chooseTiers(int $tiersId): void
    {
        $ids = (array) session(self::SESSION_KEY, []);

        if (! in_array($tiersId, array_map('intval', $ids), true)) {
            throw new AuthorizationException('Tiers non autorisé pour cette session.');
        }

        $tiers = Tiers::findOrFail($tiersId);
        Auth::guard('tiers-portail')->login($tiers);
        session()->forget(self::SESSION_KEY);

        Log::info('portail.tiers.chosen', ['tiers_id' => (int) $tiers->id]);
    }

    /**
     * Shortcut : connecte directement le Tiers sur la garde tiers-portail.
     * N'affecte pas la clé pending_tiers_ids.
     */
    public function loginSingleTiers(Tiers $tiers): void
    {
        Auth::guard('tiers-portail')->login($tiers);

        Log::info('portail.login.success', ['tiers_id' => (int) $tiers->id, 'email' => $tiers->email]);
    }

    /**
     * Purge la liste pending de la session (utilisé après login réussi ou abandon).
     */
    public function clearPending(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
