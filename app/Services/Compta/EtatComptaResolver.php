<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\EtapeCompta;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
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
     * Comptes bancaires dont le solde historique n'est pas démontrablement dans
     * le grand livre.
     *
     * La première version vérifiait seulement qu'une reprise avait été lancée :
     * elle répondait « une reprise existe-t-elle ? » quand la question qui a
     * coûté 26 000 € le 2026-07-29 est « les soldes sont-ils dans le grand
     * livre ? ». Un compte n'est couvert que si la pièce de reprise en vigueur
     * porte une ligne sur son compte 512X.
     *
     * Un compte bancaire sans compte 512X ne peut par construction pas être
     * couvert : la reprise parcourt le plan comptable et ne le voit pas.
     */
    private function comptesBancairesNonRepris(): int
    {
        $porteurs = CompteBancaire::query()
            ->where('solde_initial', '<>', 0)
            ->get();

        if ($porteurs->isEmpty()) {
            return 0;
        }

        $reprise = $this->repriseEnVigueur();

        if ($reprise === null) {
            return $porteurs->count();
        }

        $comptesCouverts = TransactionLigne::query()
            ->where('transaction_id', (int) $reprise->transaction_id)
            ->pluck('compte_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $comptes512 = Compte::query()
            ->whereNotNull('compte_bancaire_id')
            ->get()
            ->keyBy(fn (Compte $compte): int => (int) $compte->compte_bancaire_id);

        return $porteurs
            ->filter(function (CompteBancaire $banque) use ($comptesCouverts, $comptes512): bool {
                $compte = $comptes512->get((int) $banque->id);

                return $compte === null
                    || ! in_array((int) $compte->id, $comptesCouverts, true);
            })
            ->count();
    }

    /**
     * La reprise initiale en vigueur, ou null s'il n'y en a pas d'exploitable.
     *
     * Trois conditions, chacune correspondant à un scénario qui finissait comme
     * le 2026-07-29 avec une garde verte par-dessus.
     */
    private function repriseEnVigueur(): ?ANouveauGeneration
    {
        $reprise = ANouveauGeneration::query()
            ->where('origine', OrigineANouveau::RepriseInitiale)
            ->where('statut', StatutANouveau::Active)
            ->latest('id')
            ->first();

        if ($reprise === null || $reprise->transaction_id === null) {
            return null;
        }

        // Elle doit ouvrir le PREMIER exercice de l'association : viser une année
        // postérieure laisse les exercices antérieurs sans leurs soldes — et
        // c'est l'année par défaut de compta:bootstrap-an, donc le piège le plus
        // probable en exploitation.
        $premierExercice = Exercice::query()->min('annee');
        if ($premierExercice !== null && (int) $reprise->exercice_cible !== (int) $premierExercice) {
            return null;
        }

        // Un solde corrigé APRÈS la reprise n'y figure pas : séquence exacte du
        // 2026-07-29, date de solde rectifiée à 13:34, clôture à 13:35.
        $dernierEdit = CompteBancaire::query()
            ->where('solde_initial', '<>', 0)
            ->max('updated_at');

        if ($dernierEdit !== null && CarbonImmutable::parse($dernierEdit)->gt($reprise->created_at)) {
            return null;
        }

        return $reprise;
    }
}
