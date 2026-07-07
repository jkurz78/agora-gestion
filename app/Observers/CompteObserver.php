<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Compte;
use App\Models\Famille;
use Illuminate\Support\Facades\Log;

/**
 * DC-1 du programme « dissolution sous_categories → comptes ».
 *
 * Le rattachement compte → famille est dérivé par préfixe (pas de FK) : un
 * compte 6/7 dont le préfixe n'a jamais été nommé doit tout de même
 * atterrir dans une famille pour ne pas disparaître de la ventilation.
 * Cet observer matérialise cette famille de secours (nom = code, éditable
 * ensuite par l'utilisateur) — jamais bloquant, jamais silencieux (log info).
 *
 * Mirror de SousCategorieCompteObserver (même périmètre 6/7, même logique
 * de matérialisation idempotente).
 */
final class CompteObserver
{
    public function created(Compte $compte): void
    {
        if (! in_array((int) $compte->classe, [6, 7], true)) {
            return;
        }

        $code = substr($compte->numero_pcg, 0, 2);

        $famille = Famille::firstOrCreate(
            ['association_id' => (int) $compte->association_id, 'code' => $code],
            ['nom' => $code],
        );

        if ($famille->wasRecentlyCreated) {
            Log::info('[PlanComptable] Famille auto-créée pour un préfixe orphelin', [
                'compte_id' => $compte->id, 'code' => $code,
            ]);
        }
    }
}
