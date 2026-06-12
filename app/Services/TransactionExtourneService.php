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

/**
 * Extourne (contre-passation comptable) d'une transaction.
 *
 * Crée une transaction miroir D↔C qui compense l'originale dans le grand livre.
 * L'originale et le miroir restent dans les rapports — ils se compensent à zéro.
 *
 * Réservé aux transactions déjà dénouées en trésorerie (Remis, Pointé).
 * Pour Dû/En main → utiliser TransactionService::annuler() (soft-delete).
 */
final class TransactionExtourneService
{
    public function __construct(
        private readonly NumeroPieceService $numeroPiece,
        private readonly LettrageService $lettrageService,
    ) {}

    /**
     * Extourne (contre-passation) d'une transaction déjà dénouée.
     *
     * Crée une transaction miroir à montant négatif avec D↔C swap sur les lignes PD.
     * Cross-lettre les lignes tiers (411/401) entre origine et miroir.
     * Si l'origine était EnAttente, un lettrage automatique apparie les deux.
     */
    public function extourner(Transaction $origine, ExtournePayload $payload): Extourne
    {
        $this->assertSameTenant($origine);
        Gate::authorize('create', [Extourne::class, $origine]);
        $this->assertExtournable($origine);

        return DB::transaction(function () use ($origine, $payload): Extourne {
            $isCreanceOuverte = $origine->statut_reglement === StatutReglement::EnAttente;

            if ($isCreanceOuverte) {
                // Créance non encaissée : délettrer pour permettre le lettrage rapprochement.
                $this->autoDelettrerLignes($origine);
            }
            // Remis/Pointé : ne PAS toucher aux lettrages existants.
            // L'encaissement (T1↔T2) et la remise (T2↔T4) sont réels et doivent rester intacts.
            // Le 411 du miroir reste ouvert = dette de remboursement envers le tiers.

            $miroir = $this->creerTransactionMiroir($origine, $payload);
            $this->copierLignesInversees($origine, $miroir);
            $this->assertEquilibreMiroir($miroir);

            if ($isCreanceOuverte) {
                // Créance : cross-lettrage 411 entre origine et miroir (∑=0).
                $this->crossLettrerLignesTiers($origine, $miroir);
            }
            // Remis/Pointé : pas de cross-lettrage. Le 411C du miroir reste ouvert
            // jusqu'au remboursement effectif (virement/chèque → 411D lettré avec le 411C miroir).

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

            // Statuts terminaux : origine + miroir → Pointe.
            $origine->forceFill([
                'extournee_at' => now(),
                'statut_reglement' => StatutReglement::Pointe,
            ])->save();

            $miroir->forceFill(['statut_reglement' => StatutReglement::Pointe])->save();

            Log::info('Extourne — transaction extournée', [
                'association_id' => TenantContext::currentId(),
                'user_id' => (int) auth()->id(),
                'transaction_origine_id' => $origine->id,
                'transaction_extourne_id' => $miroir->id,
                'extourne_id' => $extourne->id,
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
}
