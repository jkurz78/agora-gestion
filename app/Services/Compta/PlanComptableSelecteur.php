<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\Compte;
use App\Models\Famille;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use Illuminate\Support\Collection;

/**
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
     * @param  string  $type  'depense' (classe 6), 'recette' (classe 7) ou
     *                        'immobilisation' (classe 2, hors comptes 28X)
     * @return Collection<string, array{famille: ?Famille, comptes: Collection<int, Compte>}>
     */
    public static function groupesPourType(string $type): Collection
    {
        $classe = match ($type) {
            'depense' => 6,
            'recette' => 7,
            'immobilisation' => 2,
            default => throw new \InvalidArgumentException("Type de ventilation invalide : {$type}"),
        };

        // Scope tenant déjà assuré par le global scope de TenantModel — pas
        // de filtre association_id explicite ici (doublerait le garde-fou).
        $comptes = Compte::where('classe', $classe)
            ->where('actif', true)
            ->when($classe === 2, fn ($q) => $q->where('numero_pcg', 'NOT LIKE', '28%'))
            ->orderBy('numero_pcg')
            ->get();

        if ($type === 'immobilisation') {
            // Un compte proposé mais refusé au moment d'enregistrer serait une
            // mauvaise expérience : ImmobilisationService::acquerir() exige que
            // le compte d'amortissement dérivé existe et soit actif, donc le
            // sélecteur ne doit proposer que des comptes qui satisferont ce
            // contrôle.
            //
            // Les numéros dérivés se calculent en mémoire et se chargent en une
            // seule requête : compteAmortissementPour() en interrogerait un par
            // compte de classe 2, et ce sélecteur est reconstruit à chaque rendu
            // du registre — modale d'acquisition fermée comprise.
            $numerosAmortissement = $comptes
                ->map(fn (Compte $c): string => ImmobilisationComptesSeeder::numeroAmortissementPour(
                    (string) $c->numero_pcg
                ))
                ->unique()
                ->all();

            $amortissementsActifs = Compte::whereIn('numero_pcg', $numerosAmortissement)
                ->where('actif', true)
                ->pluck('numero_pcg')
                ->map(fn ($numero): string => (string) $numero)
                ->flip();

            $comptes = $comptes->filter(
                fn (Compte $c): bool => $amortissementsActifs->has(
                    ImmobilisationComptesSeeder::numeroAmortissementPour((string) $c->numero_pcg)
                )
            )->values();
        }

        $parFamille = $comptes->groupBy(fn (Compte $c): string => $c->code_famille)->sortKeys();

        $familles = Famille::pourComptes($comptes);

        return $parFamille->map(fn (Collection $comptesDuGroupe, string $code): array => [
            'famille' => $familles->get($code),
            'comptes' => $comptesDuGroupe,
        ]);
    }
}
