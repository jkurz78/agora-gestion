<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\Compte;
use App\Models\Famille;
use Illuminate\Support\Collection;

/**
 * DC-8 — dissolution sous_categories → comptes.
 *
 * Source de données PARTAGÉE pour tout écran de sélection de compte de
 * ventilation (dépense ou recette). Reprend le regroupement par famille déjà
 * en place dans `PlanComptable::render()` (préfixe à 2 caractères du numéro
 * PCG, résolu via `Compte::getCodeFamilleAttribute()`), afin que chaque
 * écran consommateur (Deliverable 3) affiche le même arbre `<optgroup>`
 * sans dupliquer la requête ni la logique de groupement.
 */
final class PlanComptableSelecteur
{
    /**
     * Comptes actifs de la classe correspondant au type de ventilation
     * demandé, groupés par famille (préfixe à 2 caractères), triés par code
     * famille puis par numéro PCG.
     *
     * @param  string  $type  'depense' (classe 6) ou 'recette' (classe 7)
     * @return Collection<string, array{famille: ?Famille, comptes: Collection<int, Compte>}>
     */
    public static function groupesPourType(string $type): Collection
    {
        $classe = match ($type) {
            'depense' => 6,
            'recette' => 7,
            default => throw new \InvalidArgumentException("Type de ventilation invalide : {$type}"),
        };

        // Scope tenant déjà assuré par le global scope de TenantModel — pas
        // de filtre association_id explicite ici (doublerait le garde-fou).
        $comptes = Compte::where('classe', $classe)
            ->where('actif', true)
            ->orderBy('numero_pcg')
            ->get();

        $parFamille = $comptes->groupBy(fn (Compte $c): string => $c->code_famille)->sortKeys();

        $familles = Famille::pourComptes($comptes);

        return $parFamille->map(fn (Collection $comptesDuGroupe, string $code): array => [
            'famille' => $familles->get($code),
            'comptes' => $comptesDuGroupe,
        ]);
    }
}
