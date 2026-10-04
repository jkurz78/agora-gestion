<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ExtournePayload;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Events\TransactionExtournee;
use App\Models\Extourne;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\GelRattachement;
use App\Services\Compta\PartieDoubleGuard;
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
        private readonly ExerciceService $exerciceService,
        private readonly GelRattachement $gel,
    ) {}

    /**
     * Extourne (contre-passation) d'une transaction déjà dénouée.
     *
     * Crée une transaction miroir à montant négatif avec D↔C swap sur les lignes PD.
     * Les lettrages existants (T1↔T2, T2↔T4) restent intacts — le 411C du miroir
     * reste ouvert et sera soldé lors du remboursement effectif.
     *
     * Une pièce portée par un rattachement à l'exercice suivant (CCA/PCA) ne s'annule que si l'annulation
     * est datée APRÈS le dernier jour de l'exercice rattaché (D13) ; une écriture de rattachement ne
     * s'annule jamais ici : voir GelRattachement::verrouillerPieceExtournable().
     */
    public function extourner(Transaction $origine, ExtournePayload $payload): Extourne
    {
        // Une extourne est une écriture comme une autre : sa date est saisie
        // par l'utilisateur et peut tomber sur un exercice clos. Le service
        // construit sa transaction miroir sans passer par TransactionService,
        // il porte donc lui-même la garde.
        $anneeAnnulation = $this->exerciceService->anneeForDate($payload->date);

        // Les refus rapides, hors transaction, sur l'instance reçue : ils évitent d'ouvrir une
        // transaction et de prendre des verrous pour un refus évident. Ils ne tranchent rien : tout ce
        // qui dépend de l'état de la pièce (annulée, supprimée, rattachée) est relu sous verrou, dans la
        // transaction, sur la pièce que la garde rend.
        $this->exerciceService->assertOuvert($anneeAnnulation);

        $this->assertSameTenant($origine);
        Gate::authorize('create', [Extourne::class, $origine]);
        $this->assertExtournable($origine);

        return DB::transaction(function () use ($origine, $payload, $anneeAnnulation): Extourne {
            // Les verrous d'abord, AVANT la numérotation du miroir (creerTransactionMiroir()) : rattacher()
            // et retirer() tiennent la pièce puis numérotent leurs écritures. Une extourne qui numéroterait
            // la première, puis demanderait la pièce, s'inter-bloquerait avec eux.
            //
            // Ordre : l'exercice de l'ANNULATION (celui de la date saisie, non celui de la pièce), puis la
            // garde du rattachement (les lots 401/411, la pièce, ses lignes, leurs rattachements), puis la
            // numérotation (`sequences`), enfin les écritures. Pas de cycle avec rattacher() et retirer(),
            // dont l'ordre est : association, exercice N, exercice N+1, pièce, ligne, rattachements, puis
            // (rattacher() seul) la numérotation de la dotation et de l'extourne. Sur ce que les deux ont
            // en commun, l'ordre relatif est le même : un exercice avant la pièce, la pièce avant ses lignes,
            // les lignes avant les rattachements, les rattachements avant la numérotation. L'extourne ne
            // prend jamais l'association, qui précède toujours un verrou d'exercice, et n'en prend qu'un
            // seul, celui de l'annulation : aucun exercice n'est demandé après la pièce. (FactureService::annuler()
            // appelle extourner() dans sa propre transaction : ses verrous précèdent ceux-ci.)
            $this->exerciceService->assertOuvertVerrouille($anneeAnnulation);

            // Seule garde datée : l'annulation d'une pièce rattachée n'est refusée que si elle est datée au
            // plus tard le dernier jour de N. Elle rend la pièce relue sous verrou.
            $origine = $this->gel->verrouillerPieceExtournable($origine, $payload->date, $payload->dateImposee);

            // Les contrôles faits plus haut sur l'instance reçue, refaits sur la pièce verrouillée : son
            // état a pu changer entre la lecture et le verrou. Surtout `extournee_at` : une extourne
            // concurrente qui validerait entre-temps ferait neutraliser la pièce deux fois.
            $this->assertExtournable($origine);

            // Pas de délettrage ni de cross-lettrage :
            //   - Remis/Pointé : les lettrages existants (T1↔T2, T2↔T4) sont réels
            //     et doivent rester intacts. Le 411C du miroir reste ouvert = dette
            //     de remboursement, soldée quand le remboursement sera émis.
            //   - EnAttente : la modale route vers soft-delete (TransactionService::annuler),
            //     pas ici. Si on arrive ici malgré tout, le miroir est créé sans lettrage.

            $miroir = $this->creerTransactionMiroir($origine, $payload);
            $this->copierLignesInversees($origine, $miroir);
            $this->assertEquilibreMiroir($miroir);
            // Guard PD uniquement si l'originale était une Tx PD (equilibree=true).
            // Pour les Tx legacy (equilibree=false/null), le miroir est aussi non-PD.
            if ($miroir->equilibree === true) {
                PartieDoubleGuard::assertComplete($miroir);
            }

            $extourne = Extourne::create([
                'transaction_origine_id' => $origine->id,
                'transaction_extourne_id' => $miroir->id,
                'rapprochement_lettrage_id' => null,
                'created_by' => (int) auth()->id(),
            ]);

            // Capturer le statut d'origine AVANT de l'écraser.
            $statutOriginal = $origine->statut_reglement;

            // Origine → terminal (extournée, toujours Pointé).
            $origine->forceFill([
                'extournee_at' => now(),
                'statut_reglement' => StatutReglement::Pointe,
            ])->save();

            // Miroir : conditionnel selon le statut d'origine.
            // - EnAttente → annulation comptable pure, pas de flux de tréso → miroir Pointé
            // - Recu/EnMain/Pointe → remboursement à effectuer → miroir EnAttente (déjà la valeur
            //   par défaut de creerTransactionMiroir, on ne l'écrase pas)
            if ($statutOriginal === StatutReglement::EnAttente) {
                $miroir->forceFill(['statut_reglement' => StatutReglement::Pointe])->save();
            }
            // else: le miroir garde EnAttente (posé par creerTransactionMiroir)

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
            // PD : le miroir hérite du statut equilibree de l'originale.
            // Si l'originale est une Tx legacy (sans lignes PD, equilibree=false/null),
            // le miroir est aussi non-PD et le guard ne s'applique pas.
            'equilibree' => (bool) $origine->equilibree,
            'type_ecriture' => 'extourne',
            'journal' => $origine->journal,
        ]);
    }

    private function copierLignesInversees(Transaction $origine, Transaction $miroir): void
    {
        foreach ($origine->lignes()->get() as $ligne) {
            TransactionLigne::create([
                'transaction_id' => $miroir->id,
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
}
