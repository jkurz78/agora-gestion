<?php

declare(strict_types=1);

use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;

/*
|--------------------------------------------------------------------------
| Helpers partagés des tests du rattachement à l'exercice (CCA/PCA)
|--------------------------------------------------------------------------
|
| Pest charge tests/Helpers pour toute exécution, d'un seul fichier comme de la suite
| complète. RattachementEcrituresTest (le générateur et ProvisionPDService) et
| RattachementExerciceServiceTest (le service) s'en servent tous deux : déclarées dans
| l'un des deux, ces fonctions manqueraient à l'autre lancé seul, et déclarées dans les
| deux, elles se redéclareraient en suite complète.
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
