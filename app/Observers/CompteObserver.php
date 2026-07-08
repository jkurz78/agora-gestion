<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\SousCategorie;
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
 *
 * DC-7 — miroir retour SousCategorie : voir `materialiserSousCategorie()`
 * ci-dessous. Échafaudage transitoire, disparaît en DC-10 avec sous_categories.
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

        $this->materialiserSousCategorie($compte, $code);
    }

    /**
     * ⇄ Miroir retour DC-7 — échafaudage transitoire DC-7, disparaît en DC-10
     * avec sous_categories.
     *
     * Tant que des écrans/imports peuvent encore créer un Compte directement
     * (sans passer par une SousCategorie), il faut garantir la réciproque de
     * `SousCategorieCompteObserver` : un Compte de résultat (classe 6/7) NON
     * système doit avoir sa SousCategorie miroir, sous peine de rester invisible
     * des écrans qui lisent encore `sous_categories` (DC-8 pas encore basculé).
     *
     * Restreint aux comptes NON système : les comptes système (411/401/5112/530…)
     * sont des comptes de bilan ou des comptes techniques — pas de notion de
     * sous-catégorie côté legacy pour eux (même restriction 6/7 que la famille
     * ci-dessus, renforcée par est_systeme = false).
     *
     * Convergence : `SousCategorie::create()` ci-dessous déclenche
     * `SousCategorieCompteObserver::created`, qui retrouve CE Compte déjà
     * commité via son garde-fou `$existe` et sort sans recréer ni planter —
     * voir la docblock de `SousCategorieCompteObserver` pour le détail de la
     * preuve d'ordonnancement Eloquent (insert avant l'événement `created`).
     */
    private function materialiserSousCategorie(Compte $compte, string $code): void
    {
        if ($compte->est_systeme) {
            return;
        }

        $associationId = (int) $compte->association_id;
        $numero = (string) $compte->numero_pcg;

        $existe = SousCategorie::withoutGlobalScopes()
            ->where('association_id', $associationId)
            ->where('code_cerfa', $numero)
            ->exists();

        if ($existe) {
            return;
        }

        $categorie = Categorie::withoutGlobalScopes()
            ->where('association_id', $associationId)
            ->where('nom', 'LIKE', $code.' -%')
            ->first();

        if ($categorie === null) {
            $famille = Famille::withoutGlobalScopes()
                ->where('association_id', $associationId)
                ->where('code', $code)
                ->first();

            $nomCategorie = $famille !== null
                ? $code.' - '.$famille->nom
                : $code.' - '.$code;

            $categorie = Categorie::create([
                'association_id' => $associationId,
                'nom' => $nomCategorie,
                'type' => str_starts_with($code, '6') ? TypeCategorie::Depense->value : TypeCategorie::Recette->value,
            ]);
        }

        SousCategorie::create([
            'association_id' => $associationId,
            'categorie_id' => $categorie->id,
            'nom' => $compte->intitule,
            'code_cerfa' => $numero,
        ]);
    }
}
