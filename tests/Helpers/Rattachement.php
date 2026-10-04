<?php

declare(strict_types=1);

use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Helpers partagés des tests du rattachement à l'exercice (CCA/PCA)
|--------------------------------------------------------------------------
|
| Pest charge tests/Helpers pour toute exécution, d'un seul fichier comme de la suite
| complète. RattachementEcrituresTest (le générateur et ProvisionPDService) et
| RattachementExerciceServiceTest (le service) s'en servent tous deux, ainsi que
| RattachementVerrouTest pour l'inspection des verrous : déclarées dans l'un d'eux, ces
| fonctions manqueraient aux autres lancés seuls, et déclarées dans plusieurs, elles se
| redéclareraient en suite complète.
*/

/**
 * Le rattachement de l'exercice 2025 (sept. 2025 → août 2026) d'une ligne d'origine.
 * Aucun libellé : il est hérité de la ligne d'origine.
 */
function provisionPourLigne(TransactionLigne $ligne, int $exercice = 2025): Provision
{
    return Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => $exercice,
        'transaction_ligne_id' => $ligne->id,
        'montant' => max((float) $ligne->debit, (float) $ligne->credit),
        'saisi_par' => auth()->id(),
    ]);
}

/**
 * Impact d'une écriture sur le RÉSULTAT, signé : positif quand elle l'augmente.
 *
 * Seules les classes 6 et 7 comptent — c'est tout ce que lit le compte de
 * résultat. Un produit (classe 7) augmente le résultat au crédit, une charge
 * (classe 6) le diminue au débit. Un compte de classe 4 ne pèse rien : c'est
 * l'erreur de la spec de juin 2026, qui débitait 487 en croyant retirer un produit.
 */
function impactSurLeResultat(Transaction $ecriture): float
{
    $impact = 0.0;

    foreach ($ecriture->lignes as $ligne) {
        $classe = (int) $ligne->compte->classe;

        if ($classe === 7) {
            $impact += (float) $ligne->credit - (float) $ligne->debit;
        } elseif ($classe === 6) {
            $impact -= (float) $ligne->debit - (float) $ligne->credit;
        }
    }

    return round($impact, 2);
}

/**
 * Exécute $action et rend toutes les requêtes émises dans une transaction qu'elle ouvre (au-dessus
 * du niveau d'avant l'appel), dans l'ordre où elles ont été émises. Une action censée être refusée
 * s'enveloppe elle-même dans son `expect(...)->toThrow()` : l'exception ne doit pas sortir, ou les
 * requêtes seraient perdues avec elle.
 *
 * @return list<object>
 */
function rattachementRequetesDeLaTransaction(Closure $action): array
{
    $niveauAvant = DB::transactionLevel();
    $requetes = [];

    DB::listen(function ($requete) use (&$requetes, $niveauAvant): void {
        if (DB::transactionLevel() > $niveauAvant) {
            $requetes[] = $requete;
        }
    });

    $action();

    return $requetes;
}

/**
 * Exécute $action et rend les requêtes de sa transaction qui précèdent la première écriture
 * (insert, update ou delete) : les verrous, dans l'ordre où ils ont été pris.
 *
 * @return list<object>
 */
function rattachementRequetesAvantEcriture(Closure $action): array
{
    $requetes = rattachementRequetesDeLaTransaction($action);

    $premiereEcriture = collect($requetes)->search(
        fn ($requete) => preg_match('/^\s*(insert|update|delete)\b/i', $requete->sql) === 1
    );
    expect($premiereEcriture)->toBeInt();

    return array_slice($requetes, 0, $premiereEcriture);
}

/**
 * L'indice de la première requête qui lit $table (et, si $annee est donné, la porte dans ses
 * paramètres). Lève si aucune ne le fait : une lecture absente ne doit pas passer pour une lecture
 * tardive. Les requêtes examinées sont celles reçues : tronquées à la première écriture par
 * rattachementRequetesAvantEcriture(), ou non.
 *
 * @param  list<object>  $requetes
 */
function rattachementIndiceDeLaPremiere(array $requetes, string $table, ?int $annee = null): int
{
    foreach ($requetes as $i => $requete) {
        $cible = preg_match('/from\s+[`"]?(\w+)[`"]?/i', $requete->sql, $m) === 1 ? $m[1] : '?';

        if ($cible === $table && ($annee === null || in_array($annee, $requete->bindings, true))) {
            return $i;
        }
    }

    throw new RuntimeException(
        "Aucune requête sur {$table}".($annee !== null ? " ({$annee})" : '').' parmi les '.count($requetes).' requêtes examinées.'
    );
}
