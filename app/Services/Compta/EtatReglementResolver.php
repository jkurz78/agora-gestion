<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\Sens;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ReglementOperationService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dérive le statut de règlement d'une transaction depuis le grand livre PD
 * (chantier 4). Lecture seule, déterministe, source de vérité unique.
 *
 * Marche le chaînage de lettrage tiers → trésorerie (multi-hop) :
 *   - chèque recette :   411 → 5112 (encaissement) → 512X (remise)
 *   - espèces recette :  411 → 530  → 512X
 *   - virement/CB :      411 → 512X (direct)
 *   - dépense :          401 → 512X (règlement)
 *
 * Robuste aux deux structures (encaissement lumpé sur la T1 OU T2 séparée) et
 * retombe sur la colonne stockée pour les tx sans lignes PD (legacy/HelloAsso).
 */
final class EtatReglementResolver
{
    public function __construct(
        private readonly ReglementOperationService $reglementService,
    ) {}

    public function resolve(Transaction $t1): StatutReglement
    {
        // Short-circuit : transaction extournée ou extourne (contra-entry) → terminal.
        // Empêche le syncer() de ré-écraser le statut avec un état dérivé incohérent.
        if ($t1->extournee_at !== null || $t1->type_ecriture === 'extourne') {
            return StatutReglement::Pointe;
        }

        $sens = match ($t1->type) {
            TypeTransaction::Recette => Sens::Recette,
            TypeTransaction::Depense => Sens::Depense,
            default => null,
        };

        if ($sens === null) {
            return $t1->statut_reglement; // type hors recette/dépense → inchangé
        }

        $numeroTiers = $sens === Sens::Recette ? '411' : '401';
        $compteTiers = Compte::ofNumero($numeroTiers);

        if ($compteTiers === null) {
            return $t1->statut_reglement; // tenant sans schéma PD
        }

        $ligneTiers = TransactionLigne::where('transaction_id', (int) $t1->id)
            ->where('compte_id', (int) $compteTiers->id)
            ->first();

        if ($ligneTiers === null) {
            return $t1->statut_reglement; // legacy/HelloAsso : pas de ligne PD
        }

        // Étape « ouvert » : ligne de tiers non lettrée.
        if ($ligneTiers->lettrage_code === null) {
            return StatutReglement::EnAttente;
        }

        // Lettré → localiser la transaction qui porte la trésorerie.
        // T2 séparée si elle existe, sinon la T1 elle-même (encaissement lumpé).
        $t2 = $this->reglementService->trouverT2($t1);

        $txPortage = $t2 ?? $t1;

        // Ligne de trésorerie (classe 5) de la transaction portage.
        $ligneTresorerie = TransactionLigne::with('compte')
            ->where('transaction_id', (int) $txPortage->id)
            ->whereHas('compte', fn (Builder $q) => $q->where('classe', 5))
            ->first();

        if ($ligneTresorerie === null) {
            // Pas de classe 5 sur $txPortage. Deux cas possibles :
            //
            // a) Navigation « en arrière » (T2 → T1) : le resolver traite un T2
            //    et a navigué vers le T1 qui n'a pas de classe 5. La classe 5 est
            //    sur le T2 lui-même → retourner la valeur stockée (T2 n'a pas de
            //    statut dérivé indépendant — c'est une transaction de règlement).
            //
            // b) Abandon de créance (OD) : la dette tiers est lettrée sans aucun
            //    mouvement bancaire (ex: 401 D / 7xx C). Aucune classe 5 nulle part
            //    → la dette est soldée → Recu.
            if ((int) $txPortage->id !== (int) $t1->id) {
                $aClasseCinqPropre = TransactionLigne::where('transaction_id', (int) $t1->id)
                    ->whereHas('compte', fn (Builder $q) => $q->where('classe', 5))
                    ->exists();

                if ($aClasseCinqPropre) {
                    // $t1 est un T2 (encaissement/règlement) — pas de statut dérivé.
                    return $t1->statut_reglement;
                }
            }

            // Tiers lettré sans trésorerie → abandon de créance → dette soldée.
            return StatutReglement::Recu;
        }

        return $this->statutDepuisTresorerie($ligneTresorerie, $txPortage);
    }

    /**
     * Recalcule et persiste le statut miroir d'une T1 depuis le ledger.
     *
     * No-op en mode legacy (use_partie_double=false) : la colonne reste gérée
     * à l'ancienne. Idempotent : ne sauvegarde que si la valeur dérivée diffère.
     */
    public function syncer(Transaction $t1): void
    {
        if (! config('compta.use_partie_double')) {
            return;
        }

        $derive = $this->resolve($t1);

        if ($t1->statut_reglement !== $derive) {
            $t1->statut_reglement = $derive;
            $t1->save();
        }
    }

    /**
     * Statue sur le terme du chaînage à partir de la ligne de trésorerie atteinte.
     */
    private function statutDepuisTresorerie(TransactionLigne $ligneTresorerie, Transaction $txPortage): StatutReglement
    {
        $compte = $ligneTresorerie->compte;

        // 512X (banque physique) atteint → dénoué, pointé si la tx porteuse est rapprochée.
        if ($compte !== null && $compte->estBancaire()) {
            return $txPortage->rapprochement_id !== null
                ? StatutReglement::Pointe
                : StatutReglement::Recu;
        }

        // 5112 / 530 (en main) — non déposé → à remettre.
        if ($ligneTresorerie->lettrage_code === null) {
            return StatutReglement::EnMain;
        }

        // Remis : suivre vers la T4 (autre ligne 5112/530 partageant le code).
        $ligneT4 = TransactionLigne::where('lettrage_code', $ligneTresorerie->lettrage_code)
            ->where('compte_id', (int) $ligneTresorerie->compte_id)
            ->where('transaction_id', '!=', (int) $ligneTresorerie->transaction_id)
            ->first();

        if ($ligneT4 === null) {
            return StatutReglement::EnMain; // remise introuvable → dégradation prudente
        }

        $t4 = Transaction::find($ligneT4->transaction_id);

        if ($t4 === null) {
            return StatutReglement::EnMain;
        }

        $ligne512X = TransactionLigne::with('compte')
            ->where('transaction_id', (int) $t4->id)
            ->whereHas('compte', fn (Builder $q) => $q->bancaires())
            ->first();

        if ($ligne512X === null) {
            // Anomalie : 5112/530 lettré mais T4 sans 512X. Dégradation prudente vers dénoué.
            return StatutReglement::Recu;
        }

        return $t4->rapprochement_id !== null
            ? StatutReglement::Pointe
            : StatutReglement::Recu;
    }
}
