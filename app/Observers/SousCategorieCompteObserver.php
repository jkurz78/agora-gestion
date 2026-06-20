<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\SousCategorie;

/**
 * Matérialise le Compte de résultat (classe 6/7) correspondant à une sous-catégorie.
 *
 * Contexte : sur compta-v5, une sous-catégorie de résultat EST un compte (même
 * notion, recopiée dans la table `comptes` via la clé `code_cerfa` = `numero_pcg`).
 * Cette projection n'était provisionnée qu'au seed et à l'onboarding ; une
 * sous-catégorie créée ensuite via l'IHM n'avait pas de compte → ventilation PD
 * silencieusement ignorée. Cet observer garantit que créer (ou compléter) une
 * sous-catégorie matérialise son compte, quel que soit l'écran de création.
 *
 * ⚠ Échafaudage transitoire. La cible est une source de vérité unique (table
 * `comptes`), déclenchée par le mode saisie d'OD — voir l'ADR « compte unique ».
 * Le jour de cette unification, cet observer disparaît.
 *
 * Périmètre : classes 6 et 7 uniquement (comptes de résultat). Les comptes de
 * bilan (4xx/5xx) n'ont pas d'équivalent sous-catégorie — ils relèvent du mode OD.
 */
final class SousCategorieCompteObserver
{
    public function created(SousCategorie $sousCategorie): void
    {
        $this->materialiserCompte($sousCategorie);
    }

    public function updated(SousCategorie $sousCategorie): void
    {
        if ($sousCategorie->wasChanged('code_cerfa')) {
            $this->materialiserCompte($sousCategorie);
        }
    }

    private function materialiserCompte(SousCategorie $sc): void
    {
        $numero = $sc->code_cerfa;

        if ($numero === null || $numero === '') {
            return;
        }

        $classe = (int) substr($numero, 0, 1);

        // Une sous-catégorie est un compte de RÉSULTAT. Un code hors 6/7 (typo,
        // ou code de bilan saisi par erreur) ne doit pas créer de compte poubelle.
        if ($classe !== 6 && $classe !== 7) {
            return;
        }

        $associationId = (int) $sc->association_id;

        // Idempotent + robuste au scope tenant fail-closed (l'observer peut tourner
        // en contexte seeder sans TenantContext booté). On scope explicitement.
        $existe = Compte::withoutGlobalScopes()
            ->where('association_id', $associationId)
            ->where('numero_pcg', $numero)
            ->exists();

        if ($existe) {
            return;
        }

        $pourInscriptions = $sc->usages()
            ->where('usage', UsageComptable::Inscription->value)
            ->exists();

        Compte::create([
            'association_id' => $associationId,
            'numero_pcg' => $numero,
            'intitule' => $sc->nom,
            'classe' => $classe,
            'categorie_id' => $sc->categorie_id,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => $pourInscriptions,
            'lettrable' => false,
        ]);
    }
}
