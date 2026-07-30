<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\EtapeCompta;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\ANouveauGeneration;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use RuntimeException;

/**
 * Déduit l'étape comptable du tenant courant à partir des données.
 *
 * Ne prend pas d'association en paramètre : les modèles étant protégés par un
 * scope global fail-closed sur association_id, c'est à l'appelant de booter
 * TenantContext — comme le font compta:check-integrity et
 * compta:reconcilier-statuts en itérant sur les associations.
 *
 * Lecture seule : ce service n'écrit rien et ne corrige rien.
 */
final class EtatComptaResolver
{
    public function pourTenantCourant(): EtatCompta
    {
        // Fail-closed. TenantScope injecte `1 = 0` quand le contexte n'est pas
        // booté : sans ce garde-fou, le résolveur ne verrait aucune donnée, se
        // déclarerait opérationnel, et toutes les gardes qui s'appuient sur lui
        // laisseraient passer l'opération. Pour la seule classe du chantier dont
        // le métier est de refuser, échouer en s'ouvrant est le mauvais sens.
        if (! TenantContext::hasBooted()) {
            throw new RuntimeException(
                'EtatComptaResolver exige un TenantContext booté : sans lui, aucune donnée n’est visible et l’état serait faussement opérationnel.'
            );
        }

        $blocages = [];

        $nonConverties = $this->transactionsHorsPartieDouble();
        if ($nonConverties > 0) {
            $blocages[EtapeCompta::BackfillRequis->value] = sprintf(
                '%d opération(s) n’ont pas d’écriture comptable complète.',
                $nonConverties,
            );
        }

        $comptesNonRepris = $this->comptesBancairesNonRepris();
        if ($comptesNonRepris > 0) {
            $blocages[EtapeCompta::RepriseInitialeRequise->value] = sprintf(
                '%d compte(s) bancaire(s) portent un solde historique jamais entré dans le grand livre.',
                $comptesNonRepris,
            );
        }

        return new EtatCompta($blocages);
    }

    /**
     * Critère du backfill lui-même (equilibree = false), avec l'exclusion
     * HelloAsso d'assert-pd-complete : ces transactions restent legacy par
     * construction, leur enrichissement PD est best-effort au sync.
     *
     * Le critère se limite volontairement au drapeau `equilibree`, là où
     * compta:assert-pd-complete vérifie en plus la présence de lignes et
     * l'équilibre débit/crédit de chacune. Un contrôle par requête ne peut pas
     * payer ce coût ; le critère complet reste appliqué au déploiement.
     */
    private function transactionsHorsPartieDouble(): int
    {
        return Transaction::query()
            ->whereNull('helloasso_order_id')
            ->where('equilibree', false)
            ->count();
    }

    /**
     * Soldes historiques non repris : des comptes bancaires portent un solde
     * initial non nul et aucune reprise initiale n'a jamais été créée.
     *
     * C'est la règle qui ferme le défaut du 2026-07-29 : une clôture avait été
     * acceptée sans reprise, produisant une ouverture amputée de 26 000 € sans
     * qu'aucune garde ne le signale.
     *
     * Lecture sur `comptes_bancaires`, la source de vérité — comme
     * BootstrapANouveauService. La copie dans `comptes.solde_initial` n'est
     * rafraîchie par rien et peut être périmée : c'est précisément l'écart qui a
     * été constaté ce jour-là.
     *
     * Une association qui démarre à zéro ne porte aucun solde non nul et
     * traverse cette étape sans rien faire : cas nominal, pas exception.
     */
    private function comptesBancairesNonRepris(): int
    {
        $avecSolde = CompteBancaire::query()
            ->whereNotNull('solde_initial')
            ->where('solde_initial', '<>', 0)
            ->count();

        if ($avecSolde === 0) {
            return 0;
        }

        $repriseFaite = ANouveauGeneration::query()
            ->where('origine', OrigineANouveau::RepriseInitiale)
            ->where('statut', StatutANouveau::Active)
            ->exists();

        return $repriseFaite ? 0 : $avecSolde;
    }
}
