<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Operation;

/**
 * Arbre de regroupement pour le sélecteur d'opérations (groupe par compte,
 * puis par type d'opération).
 *
 * SEL-03 : l'arbre part des opérations ÉLIGIBLES, pas de
 * TypeOperation::actif()->operations()->forExercice(). Un type désactivé
 * après coup ou une opération datée sur une autre période ne doivent pas
 * faire disparaître des montants bien réels de l'exercice affiché.
 *
 * L'id de premier niveau n'est qu'une clé de groupement locale à ce widget
 * JS — jamais renvoyée au serveur (seuls les op.id le sont via
 * selectedOperationIds, revalidés en SEL-04).
 *
 * Partagée entre le compte de résultat par opérations
 * (RapportCompteResultatOperations) et le rapport « Budget par opérations » :
 * deux écrans, un seul sélecteur.
 */
final class ArbreSelecteurOperations
{
    /**
     * @param  list<int>  $eligibleIds
     * @return list<array{id: int, nom: string, types: list<array>}>
     */
    public function construire(array $eligibleIds): array
    {
        if ($eligibleIds === []) {
            return [];
        }

        $operations = Operation::whereIn('id', $eligibleIds)
            ->with('typeOperation.compte')
            ->orderBy('nom')
            ->get();

        $tree = [];
        foreach ($operations as $op) {
            $type = $op->typeOperation;
            $compte = $type?->compte;

            $cId = (int) ($compte?->id ?? 0);
            $tId = (int) ($type?->id ?? 0);

            if (! isset($tree[$cId])) {
                $tree[$cId] = [
                    'id' => $cId,
                    'nom' => $compte?->intitule ?? '—',
                    'types_map' => [],
                ];
            }
            if (! isset($tree[$cId]['types_map'][$tId])) {
                $tree[$cId]['types_map'][$tId] = [
                    'id' => $tId,
                    'nom' => $type?->nom ?? 'Sans type',
                    'operations' => [],
                ];
            }

            $tree[$cId]['types_map'][$tId]['operations'][] = [
                'id' => (int) $op->id,
                'nom' => $op->nom,
            ];
        }

        $result = [];
        foreach ($tree as $groupe) {
            $types = array_values($groupe['types_map']);
            usort($types, fn (array $a, array $b): int => strcmp($a['nom'], $b['nom']));
            unset($groupe['types_map']);
            $groupe['types'] = $types;
            $result[] = $groupe;
        }
        usort($result, fn (array $a, array $b): int => strcmp($a['nom'], $b['nom']));

        return $result;
    }
}
