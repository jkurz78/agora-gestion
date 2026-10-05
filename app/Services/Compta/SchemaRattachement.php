<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\CasProvision;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;

/**
 * Les deux écritures d'un rattachement à l'exercice (dotation au dernier jour de N, extourne au premier
 * jour de N+1), calculées SANS rien écrire : le sens, les dates, le libellé et le net porté sur la pièce.
 *
 * C'est la SOURCE UNIQUE de ce que le rattachement écrit. EcritureGenerator::pourRattachement() l'écrit
 * en base, et l'aperçu de l'écran (la modale « Constater d'avance », spec § 6.3) l'affiche : ni l'un ni
 * l'autre ne recalcule le sens de son côté. C'est exactement le défaut qui a produit l'erreur de la spec de
 * juin — une écriture que personne n'avait regardée, calculée à part de ce qu'on en disait.
 *
 * Le schéma est celui du § 5 de la spec :
 *   PCA  7xx D / 487 C → 487 D / 7xx C      CCA  486 D / 6xx C → 6xx D / 486 C
 *   PAR  418 D / 7xx C → 7xx D / 418 C      FNP  6xx D / 408 C → 408 D / 6xx C
 * Le sens se lit sur le cas (CasProvision::dotationDebiteLeResultat()), jamais sur le signe d'un montant,
 * qui est toujours strictement positif.
 *
 * Pure : elle ne lit que le paramétrage d'exercice de l'association (les dates), et n'écrit ni ne
 * verrouille rien. Elle ne résout aucun compte : elle rend des NUMÉROS, que l'appelant relie à ses comptes.
 */
final class SchemaRattachement
{
    /** Longueur de la colonne `libelle` de `transactions` et de `transaction_lignes`. */
    private const LIBELLE_LONGUEUR_MAX = 255;

    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * La dotation ($extourne = false) ou l'extourne ($extourne = true) du rattachement.
     *
     * @param  string  $numeroCompteResultat  le numéro du compte 6xx/7xx de l'origine (ou saisi, au lot 2)
     * @param  float  $montant  strictement positif : le montant de l'origine, jamais saisi (D12)
     * @param  int  $exercice  l'exercice N du rattachement : la dotation y vit, l'extourne dans N+1
     * @param  string  $libelleEffectif  le libellé de l'origine (Provision::libelleEffectif())
     * @return array{
     *     date: CarbonImmutable,
     *     libelle: string,
     *     montant_total: float,
     *     lignes: array{
     *         resultat: array{numero_pcg: string, debit: float, credit: float},
     *         contrepartie: array{numero_pcg: string, debit: float, credit: float},
     *     },
     * }
     */
    public function ecriture(
        CasProvision $cas,
        string $numeroCompteResultat,
        float $montant,
        int $exercice,
        string $libelleEffectif,
        bool $extourne,
    ): array {
        $montant = round($montant, 2);

        // La dotation débite le résultat pour une PCA (on retire le produit) ; l'extourne inverse.
        // Le compte de régularisation prend l'autre jambe.
        $resultatAuDebit = $extourne
            ? ! $cas->dotationDebiteLeResultat()
            : $cas->dotationDebiteLeResultat();

        $debitResultat = $resultatAuDebit ? $montant : 0.0;
        $creditResultat = $resultatAuDebit ? 0.0 : $montant;

        // `montant_total` est SIGNÉ : c'est le net de la ligne 6/7, un produit au crédit et une charge au
        // débit, que `compta:check-integrity` (CHECK 3) compare au champ. La dotation d'une PCA ou d'une CCA
        // retire un produit ou une charge : son net est négatif. Son extourne le rend : positive. À ne pas
        // confondre avec une extourne ordinaire (TransactionExtourneService), qui nie le montant de la pièce
        // extournée : celle-là est négative parce que son origine est positive. Ici c'est la dotation qui est
        // négative, et son miroir `type_ecriture = 'extourne'` qui est positif. Avec une magnitude positive,
        // chaque dotation serait signalée par le cron quotidien et `--fix` réécrirait le champ.
        $montantTotal = $cas->classe() === 7
            ? $creditResultat - $debitResultat
            : $debitResultat - $creditResultat;

        // Les dates viennent du réglage d'exercice de l'association, jamais d'un 1er septembre en dur :
        // une association en exercice civil aurait des extournes datées de septembre, et un numéro de pièce
        // rattaché au mauvais exercice puisqu'il se dérive de cette même date.
        $date = $extourne
            ? $this->exerciceService->dateRange($exercice + 1)['start']
            : $this->exerciceService->dateRange($exercice)['end'];

        // Le cas en toutes lettres, pas en sigle : ce libellé est lu au journal OD et au grand livre.
        $libelle = ($extourne ? 'Extourne' : 'Rattachement').' — '.mb_strtolower($cas->label()).' : '.$libelleEffectif;

        // Tronqué à la colonne : le préfixe en consomme une quarantaine, et MySQL refuse l'insertion d'un
        // libellé plus long là où SQLite l'accepte en silence. On garde 255 caractères en tout, le « … »
        // compris : donc 255 − 1 de texte.
        if (mb_strlen($libelle) > self::LIBELLE_LONGUEUR_MAX) {
            $libelle = mb_substr($libelle, 0, self::LIBELLE_LONGUEUR_MAX - 1).'…';
        }

        return [
            'date' => $date,
            'libelle' => $libelle,
            'montant_total' => $montantTotal,
            'lignes' => [
                'resultat' => [
                    'numero_pcg' => $numeroCompteResultat,
                    'debit' => $debitResultat,
                    'credit' => $creditResultat,
                ],
                // Contrepartie de régularisation : l'autre jambe.
                'contrepartie' => [
                    'numero_pcg' => $cas->compteContrepartie(),
                    'debit' => $creditResultat,
                    'credit' => $debitResultat,
                ],
            ],
        ];
    }
}
