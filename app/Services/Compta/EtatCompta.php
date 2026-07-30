<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\EtapeCompta;
use InvalidArgumentException;

/**
 * État comptable d'une association à un instant donné — dérivé, jamais stocké.
 *
 * L'objet ne porte qu'un champ : les blocages détectés. L'étape courante en est
 * déduite, elle n'est pas mémorisée. Stocker les deux rendait représentable un
 * état qui se contredit — opérationnel et bloqué à la fois — soit exactement le
 * défaut de seconde source de vérité que ce chantier corrige.
 */
final readonly class EtatCompta
{
    /**
     * @param  array<string, string>  $blocages  Conditions bloquantes détectées,
     *                                           indexées par EtapeCompta::value.
     *                                           Chaque cause est une phrase complète
     *                                           terminée par un point : causes() les
     *                                           concatène. Jamais de commande.
     *
     * @throws InvalidArgumentException Si une clé n'est pas une valeur d'EtapeCompta,
     *                                  ou si Operationnel est utilisé comme clé.
     *                                  Sans ce contrôle, une clé mal orthographiée
     *                                  serait affichée par le diagnostic mais
     *                                  invisible d'exige() : une garde passerait
     *                                  sans rien signaler.
     */
    public function __construct(public array $blocages)
    {
        foreach (array_keys($this->blocages) as $cle) {
            $etape = EtapeCompta::tryFrom((string) $cle);

            if ($etape === null || $etape === EtapeCompta::Operationnel) {
                throw new InvalidArgumentException(
                    sprintf('Clé de blocage invalide : %s.', (string) $cle)
                );
            }
        }
    }

    /**
     * L'étape courante : le premier blocage dans l'ordre de déclaration de
     * l'énumération. Déduite à la lecture, ce qui rend vraie la promesse d'ordre
     * de EtapeCompta au lieu de la faire reposer sur l'ordre d'évaluation du
     * résolveur, que rien n'imposerait.
     */
    public function etape(): EtapeCompta
    {
        foreach (EtapeCompta::cases() as $cas) {
            if (isset($this->blocages[$cas->value])) {
                return $cas;
            }
        }

        return EtapeCompta::Operationnel;
    }

    public function estOperationnel(): bool
    {
        return $this->blocages === [];
    }

    /**
     * Cette condition précise fait-elle partie des blocages ?
     *
     * Une garde exprime ainsi son intention (« le backfill est-il requis ? »)
     * sans dépendre de l'étape courante, qui ne révèle que le premier blocage.
     *
     * `Operationnel` n'étant jamais une clé valide, exige(Operationnel) vaut
     * toujours false : la question « cette association est-elle opérationnelle ? »
     * se pose à estOperationnel().
     */
    public function exige(EtapeCompta $condition): bool
    {
        return isset($this->blocages[$condition->value]);
    }

    /**
     * Les causes, concaténées, prêtes à afficher sur n'importe quel support.
     *
     * Ne contient jamais de commande : le remède dépend du support et du tenant,
     * c'est à l'appelant de le composer.
     */
    public function causes(): string
    {
        return implode(' ', $this->blocages);
    }
}
