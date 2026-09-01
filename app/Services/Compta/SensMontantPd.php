<?php

declare(strict_types=1);

namespace App\Services\Compta;

/**
 * Convention de signe de la partie double, en un seul endroit.
 *
 * Une ligne de classe 6 est positive au débit, une ligne de classe 7 est positive
 * au crédit. Un compte de sens inverse — 709 « Gratuités accordées » au débit, 609
 * « Rabais obtenus » au crédit — produit donc un montant négatif, qui réduit
 * respectivement les produits et les charges. C'est ce que le compte de résultat
 * attend d'un contra-compte.
 *
 * Les affectations (`transaction_ligne_affectations`) ne portent qu'une MAGNITUDE
 * positive : une part de la ligne parente, sans signe propre. Leur sens doit donc
 * être emprunté à cette ligne, sans quoi un contra-compte éclaté sur plusieurs
 * opérations remonterait positif — c'est-à-dire à l'envers.
 *
 * Les méthodes rendent un fragment SQL, pas une Expression, pour que l'appelant
 * puisse y accoler son propre alias (` as montant`) comme le fait déjà tout
 * CompteResultatBuilder. Les alias reçus sont des littéraux du code, jamais une
 * entrée utilisateur.
 */
final class SensMontantPd
{
    /**
     * Montant signé d'une ligne PD agrégée.
     *
     * @param  int  $classe  6 (charges) ou 7 (produits)
     */
    public static function ligne(int $classe, string $alias = 'tl'): string
    {
        return $classe === 7
            ? "SUM({$alias}.credit) - SUM({$alias}.debit)"
            : "SUM({$alias}.debit) - SUM({$alias}.credit)";
    }

    /**
     * Somme signée d'affectations : la magnitude `montant`, portée au sens de la
     * ligne parente.
     *
     * Le CASE reste à l'intérieur du SUM : c'est une expression d'agrégat, donc
     * légale sous ONLY_FULL_GROUP_BY sans que debit/credit figurent au GROUP BY.
     * CASE WHEN est portable MySQL et SQLite — aucune fonction spécifique à un
     * moteur, comme partout ailleurs dans les rapports.
     *
     * @param  int  $classe  6 (charges) ou 7 (produits)
     */
    public static function affectation(int $classe, string $tlAlias = 'tl', string $tlaAlias = 'tla'): string
    {
        $sens = $classe === 7
            ? "CASE WHEN {$tlAlias}.credit >= {$tlAlias}.debit THEN 1 ELSE -1 END"
            : "CASE WHEN {$tlAlias}.debit >= {$tlAlias}.credit THEN 1 ELSE -1 END";

        return "SUM({$tlaAlias}.montant * ({$sens}))";
    }
}
