<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ExtournePayload;
use App\Enums\StatutFacture;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeRapprochement;
use App\Events\TransactionExtournee;
use App\Models\Extourne;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\LettrageService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TransactionExtourneService
{
    public function __construct(
        private readonly NumeroPieceService $numeroPiece,
        private readonly LettrageService $lettrageService,
    ) {}

    /**
     * Extourne (annulation) d'une transaction recette.
     *
     * Crée une transaction miroir à montant négatif. Si l'origine était EnAttente,
     * un lettrage automatique apparie origine et extourne (Step 6 — pas implémenté ici).
     */
    public function extourner(Transaction $origine, ExtournePayload $payload): Extourne
    {
        $this->assertSameTenant($origine);
        Gate::authorize('create', [Extourne::class, $origine]);
        $this->assertExtournable($origine);

        return DB::transaction(function () use ($origine, $payload): Extourne {
            // Détecter les T2 cascadables AVANT auto-délettrage (le lien est via lettrage 411)
            $t2sCascadables = $this->detecterT2Cascadables($origine);

            // Auto-délettrage des lignes lettrées de l'origine AVANT création du miroir.
            // Si le délettrage échoue (LettrageService throw), pas de miroir créé —
            // le DB::transaction parent rollback tout.
            $this->autoDelettrerLignes($origine);

            $miroir = $this->creerTransactionMiroir($origine, $payload);
            $this->copierLignesInversees($origine, $miroir);
            $this->assertEquilibreMiroir($miroir);

            // Cascade : extourner chaque T2 cascadable
            $miroirsT2 = [];
            foreach ($t2sCascadables as $t2) {
                $miroirsT2[] = $this->extournerSansCascade($t2);
            }

            // Lettrage des lignes tiers (411/401) :
            // Si cascade → toutes les lignes de toutes origines + miroirs en un seul groupe
            // Sinon → paires origine↔miroir comme avant
            if (count($miroirsT2) > 0) {
                $this->lettrerGroupeTiers($origine, $miroir, $t2sCascadables, $miroirsT2);
            } else {
                // Cross-lettrage PD des lignes tiers (411/401) entre origine et miroir
                $this->crossLettrerLignesTiers($origine, $miroir);
            }

            $lettrageId = null;
            if ($origine->statut_reglement === StatutReglement::EnAttente) {
                $lettrage = $this->creerLettrage($origine, $miroir, $payload);
                $lettrageId = $lettrage->id;

                $origine->forceFill([
                    'rapprochement_id' => $lettrage->id,
                    'statut_reglement' => StatutReglement::Pointe,
                ])->save();

                $miroir->forceFill([
                    'rapprochement_id' => $lettrage->id,
                    'statut_reglement' => StatutReglement::Pointe,
                ])->save();
            }

            $extourne = Extourne::create([
                'transaction_origine_id' => $origine->id,
                'transaction_extourne_id' => $miroir->id,
                'rapprochement_lettrage_id' => $lettrageId,
                'created_by' => (int) auth()->id(),
            ]);

            $origine->forceFill(['extournee_at' => now()])->save();

            Log::info('Extourne — transaction extournée', [
                'association_id' => TenantContext::currentId(),
                'user_id' => (int) auth()->id(),
                'transaction_origine_id' => $origine->id,
                'transaction_extourne_id' => $miroir->id,
                'extourne_id' => $extourne->id,
                'cascade_t2_ids' => collect($t2sCascadables)->pluck('id')->all(),
            ]);

            // Dispatch INSIDE transaction so listeners can roll back via throw
            event(new TransactionExtournee($extourne));

            return $extourne;
        });
    }

    private function creerTransactionMiroir(Transaction $origine, ExtournePayload $payload): Transaction
    {
        return Transaction::create([
            'type' => $origine->type,
            'date' => $payload->date->toDateString(),
            'libelle' => $payload->libelle,
            'montant_total' => -1 * (float) $origine->montant_total,
            'mode_paiement' => $payload->modePaiement,
            'tiers_id' => $origine->tiers_id,
            'reference' => null,
            'compte_id' => $origine->compte_id,
            'notes' => $payload->notes,
            'saisi_par' => (int) auth()->id(),
            'rapprochement_id' => null,
            'remise_id' => null,
            'reglement_id' => null,
            'numero_piece' => $this->numeroPiece->assign($payload->date),
            'piece_jointe_path' => null,
            'piece_jointe_nom' => null,
            'piece_jointe_mime' => null,
            'helloasso_order_id' => null,
            'helloasso_cashout_id' => null,
            'helloasso_payment_id' => null,
            'statut_reglement' => StatutReglement::EnAttente,
            // PD
            'equilibree' => true,
            'type_ecriture' => 'extourne',
            'journal' => $origine->journal,
        ]);
    }

    private function copierLignesInversees(Transaction $origine, Transaction $miroir): void
    {
        foreach ($origine->lignes()->get() as $ligne) {
            TransactionLigne::create([
                'transaction_id' => $miroir->id,
                // Legacy fields
                'sous_categorie_id' => $ligne->sous_categorie_id,
                'operation_id' => $ligne->operation_id,
                'seance' => $ligne->seance,
                'montant' => -1 * (float) $ligne->montant,
                'notes' => $ligne->notes,
                'piece_jointe_path' => null,
                'helloasso_item_id' => null,
                // PD fields — D↔C swap (montants positifs, sens inversé)
                'compte_id' => $ligne->compte_id,
                'debit' => (float) $ligne->credit,
                'credit' => (float) $ligne->debit,
                'tiers_id' => $ligne->tiers_id,
                'libelle' => $ligne->libelle,
            ]);
        }
    }

    /**
     * Apparie, par lettrage PD, les lignes tiers (compte lettrable, tiers_id non nul)
     * entre l'origine (auto-délettrée) et le miroir (lignes D↔C inversées).
     *
     * Pour chaque ligne de l'origine (non lettrée, avec tiers_id), on cherche dans le
     * miroir la ligne symétrique : même compte_id, même tiers_id, et sens inversé
     * (debit_origine == credit_miroir && credit_origine == debit_miroir).
     * Chaque paire est lettrée indépendamment (code distinct par paire).
     */
    private function crossLettrerLignesTiers(Transaction $origine, Transaction $miroir): void
    {
        $lignesOrigine = TransactionLigne::where('transaction_id', (int) $origine->id)
            ->whereNotNull('compte_id')
            ->whereNotNull('tiers_id')
            ->whereNull('lettrage_code')
            ->get();

        if ($lignesOrigine->isEmpty()) {
            return;
        }

        $lignesMiroir = TransactionLigne::where('transaction_id', (int) $miroir->id)
            ->whereNotNull('compte_id')
            ->whereNotNull('tiers_id')
            ->whereNull('lettrage_code')
            ->get();

        if ($lignesMiroir->isEmpty()) {
            return;
        }

        $miroirRestantes = $lignesMiroir->values()->all();

        foreach ($lignesOrigine as $lo) {
            foreach ($miroirRestantes as $idx => $lm) {
                if (
                    (int) $lo->compte_id === (int) $lm->compte_id
                    && (int) $lo->tiers_id === (int) $lm->tiers_id
                    && bccomp((string) $lo->debit, (string) $lm->credit, 2) === 0
                    && bccomp((string) $lo->credit, (string) $lm->debit, 2) === 0
                ) {
                    $this->lettrageService->lettrer(
                        collect([$lo, $lm]),
                        null,
                        "Cross-lettrage extourne T#{$origine->id} → miroir T#{$miroir->id}"
                    );
                    unset($miroirRestantes[$idx]);
                    break;
                }
            }
        }
    }

    /**
     * Vérifie paranoïaquement que les lignes PD du miroir sont équilibrées (∑D = ∑C).
     * Un miroir issu d'une origine équilibrée après D↔C swap doit toujours l'être —
     * ce guard attrape les bugs de copierLignesInversees avant qu'ils atteignent la DB.
     * Pas d'action si le miroir n'a aucune ligne PD (Tx legacy pure).
     */
    private function assertEquilibreMiroir(Transaction $miroir): void
    {
        $lignesPD = TransactionLigne::where('transaction_id', (int) $miroir->id)
            ->whereNotNull('compte_id')
            ->get();

        if ($lignesPD->isEmpty()) {
            return;
        }

        app(EcritureGenerator::class)->assertEquilibre($lignesPD);
    }

    /**
     * Crée le lettrage automatique : un RapprochementBancaire de type Lettrage
     * directement Verrouillé (∑=0, solde inchangé).
     */
    private function creerLettrage(Transaction $origine, Transaction $miroir, ExtournePayload $payload): RapprochementBancaire
    {
        $solde = $this->soldeActuelCompte((int) $origine->compte_id);

        return RapprochementBancaire::create([
            'compte_id' => $origine->compte_id,
            'date_fin' => $payload->date->toDateString(),
            'solde_ouverture' => $solde,
            'solde_fin' => $solde,
            'statut' => StatutRapprochement::Verrouille,
            'type' => TypeRapprochement::Lettrage,
            'saisi_par' => (int) auth()->id(),
            'verrouille_at' => now(),
        ]);
    }

    /**
     * Vérifie que la transaction appartient au tenant courant.
     * Ceinture-bretelles en plus du scope global qui agit déjà côté query builder.
     */
    private function assertSameTenant(Transaction $origine): void
    {
        if ((int) $origine->association_id !== (int) TenantContext::currentId()) {
            throw new RuntimeException('Transaction introuvable.');
        }
    }

    /**
     * Vérifie tous les guards d'éligibilité métier avec un message francisé spécifique.
     */
    private function assertExtournable(Transaction $origine): void
    {
        if ($origine->trashed()) {
            throw new RuntimeException('Cette transaction a été supprimée et ne peut pas être annulée.');
        }

        if ($origine->extournee_at !== null) {
            throw new RuntimeException('Cette transaction a déjà été annulée.');
        }

        if ($origine->estUneExtourne) {
            throw new RuntimeException('Cette transaction est elle-même une annulation et ne peut pas être annulée.');
        }

        if ($origine->helloasso_order_id !== null) {
            throw new RuntimeException('Les transactions issues de HelloAsso ne peuvent pas être annulées manuellement.');
        }

        $factureValidee = $origine->factures()
            ->where('statut', StatutFacture::Validee)
            ->first();
        if ($factureValidee !== null) {
            throw new RuntimeException(
                "Cette transaction est portée par la facture {$factureValidee->numero}. Annulez la facture pour la libérer."
            );
        }
    }

    /**
     * Délettre toutes les lignes de l'origine qui portent un lettrage_code non null.
     *
     * Délègue à LettrageService::autoDelettrerLignesDe (rule-of-three — Vague 3b).
     * Cas de non-action : si aucune ligne ne porte de lettrage_code (Tx legacy pure ou
     * créance ouverte non encaissée), retourne sans rien faire.
     */
    private function autoDelettrerLignes(Transaction $origine): void
    {
        $motif = "Auto-délettrage suite à extourne de TX#{$origine->id}";
        $this->lettrageService->autoDelettrerLignesDe($origine, $motif);
    }

    /**
     * Solde courant du compte = solde_fin du dernier rapprochement bancaire
     * verrouillé (type Bancaire), ou 0 si aucun.
     */
    private function soldeActuelCompte(int $compteId): float
    {
        $dernier = RapprochementBancaire::query()
            ->where('compte_id', $compteId)
            ->where('type', TypeRapprochement::Bancaire)
            ->where('statut', StatutRapprochement::Verrouille)
            ->orderByDesc('date_fin')
            ->orderByDesc('id')
            ->first();

        return $dernier ? (float) $dernier->solde_fin : 0.0;
    }

    /**
     * Détecte les T2 (encaissements) liés à l'origine via lettrage 411/401,
     * qui n'ont pas encore été remisés (remise_id IS NULL).
     *
     * Doit être appelé AVANT auto-délettrage car le lien T1↔T2 passe par le lettrage_code.
     *
     * Seuls les encaissements (lignes 411/401 au CRÉDIT) sont cascadés :
     * - T1 (créance) a 411D → cascade T2 (encaissement, 411C)
     * - T2 (encaissement) a 411C → ne cascade PAS T1 (créance, 411D)
     *
     * @return list<Transaction>
     */
    private function detecterT2Cascadables(Transaction $origine): array
    {
        // Trouver les lettrage_codes des lignes tiers de l'origine
        $codesLettrage = TransactionLigne::where('transaction_id', (int) $origine->id)
            ->whereNotNull('compte_id')
            ->whereNotNull('tiers_id')
            ->whereNotNull('lettrage_code')
            ->pluck('lettrage_code')
            ->unique()
            ->all();

        if (empty($codesLettrage)) {
            return [];
        }

        // Trouver les lignes des AUTRES transactions qui partagent ces codes
        // et qui sont au CRÉDIT (= encaissements). Les créances ont 411D, pas 411C.
        // On ne cascade que les encaissements (T2), jamais les créances (T1).
        $t2Ids = TransactionLigne::whereIn('lettrage_code', $codesLettrage)
            ->where('transaction_id', '!=', (int) $origine->id)
            ->whereNotNull('tiers_id')
            ->where('credit', '>', 0)  // Encaissement : ligne 411 au crédit
            ->pluck('transaction_id')
            ->unique()
            ->all();

        if (empty($t2Ids)) {
            return [];
        }

        // Filtrer : non extournée, non supprimée, remise_id IS NULL (chèque pas encore déposé)
        return Transaction::whereIn('id', $t2Ids)
            ->whereNull('extournee_at')
            ->whereNull('remise_id')
            ->get()
            ->all();
    }

    /**
     * Extourne une transaction sans cascade ni lettrage tiers (appelé pour les T2 cascadés).
     * Le lettrage tiers est géré en groupe par l'appelant.
     */
    private function extournerSansCascade(Transaction $t2): Transaction
    {
        $this->assertSameTenant($t2);
        // Pas de Gate::authorize ici — l'autorisation a été vérifiée sur T1,
        // et T2 est un encaissement automatique lié.

        $payload = ExtournePayload::fromOrigine($t2);

        // Auto-délettrage de T2 (probablement déjà fait par le délettrage de T1,
        // mais idempotent — si rien à délettre, retourne sans rien faire)
        $this->autoDelettrerLignes($t2);

        $miroir = $this->creerTransactionMiroir($t2, $payload);
        $this->copierLignesInversees($t2, $miroir);
        $this->assertEquilibreMiroir($miroir);

        Extourne::create([
            'transaction_origine_id' => $t2->id,
            'transaction_extourne_id' => $miroir->id,
            'rapprochement_lettrage_id' => null,
            'created_by' => (int) auth()->id(),
        ]);

        $t2->forceFill(['extournee_at' => now()])->save();

        Log::info('Extourne — cascade T2 extournée', [
            'association_id' => TenantContext::currentId(),
            'user_id' => (int) auth()->id(),
            'transaction_origine_id' => $t2->id,
            'transaction_extourne_id' => $miroir->id,
        ]);

        event(new TransactionExtournee(
            Extourne::where('transaction_origine_id', $t2->id)->firstOrFail()
        ));

        return $miroir;
    }

    /**
     * Lettre toutes les lignes tiers (411/401) des origines et de leurs miroirs
     * en un seul groupe par (compte_id, tiers_id).
     *
     * Exemple T1+T2 avec cascade :
     *   T1.411D + T2.411C + miroir-T1.411C + miroir-T2.411D → 1 code
     *   ∑D = ∑C → équilibré
     *
     * @param  list<Transaction>  $t2s
     * @param  list<Transaction>  $miroirsT2
     */
    private function lettrerGroupeTiers(
        Transaction $origine,
        Transaction $miroirOrigine,
        array $t2s,
        array $miroirsT2,
    ): void {
        // Collecter toutes les transaction IDs impliquées
        $txIds = collect([(int) $origine->id, (int) $miroirOrigine->id]);
        foreach ($t2s as $t2) {
            $txIds->push((int) $t2->id);
        }
        foreach ($miroirsT2 as $m) {
            $txIds->push((int) $m->id);
        }

        // Charger toutes les lignes tiers (411/401) non lettrées
        $lignesTiers = TransactionLigne::whereIn('transaction_id', $txIds->all())
            ->whereNotNull('compte_id')
            ->whereNotNull('tiers_id')
            ->whereNull('lettrage_code')
            ->get();

        if ($lignesTiers->isEmpty()) {
            return;
        }

        // Grouper par (compte_id, tiers_id) et lettrer chaque groupe
        $grouped = $lignesTiers->groupBy(fn (TransactionLigne $l) => $l->compte_id.'-'.$l->tiers_id);

        foreach ($grouped as $key => $lignes) {
            // Vérifier l'équilibre avant d'appeler lettrer (qui le vérifie aussi)
            $sumD = $lignes->sum(fn ($l) => (float) $l->debit);
            $sumC = $lignes->sum(fn ($l) => (float) $l->credit);

            if (bccomp((string) $sumD, (string) $sumC, 2) !== 0) {
                // Déséquilibré — ne pas lettrer ce groupe (cas edge, ne devrait pas arriver)
                Log::warning('Lettrage groupé extourne — groupe déséquilibré, skip', [
                    'key' => $key,
                    'sum_debit' => $sumD,
                    'sum_credit' => $sumC,
                ]);

                continue;
            }

            $this->lettrageService->lettrer(
                $lignes,
                null,
                "Lettrage groupé extourne T#{$origine->id} + cascade"
            );
        }
    }
}
